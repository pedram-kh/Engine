<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Jobs\GenerateScriptImageThumbnail;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptHook;
use App\Modules\Strategy\Models\WaveScriptImage;
use App\Modules\Strategy\Models\WaveScriptLine;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use Aws\S3\S3Client;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Str;

/**
 * Reference images of a hook or a line (PL-3.2b, ruling 11). The 10-per-block and 300 MB-per-script limits are a RESERVATION made
 * at `init` under the script row lock (parallel uploads each see the others' reservations) and are CHECKED AGAIN at `complete`,
 * after the stored object has been read: the object's own size and MIME and its header decide, never the request. Reads go through
 * the API only (no presigned GET is ever returned). Every write opens {@see ScriptGuard::transaction()} (wave lock, then script
 * lock). Tech-debt row 40: no virus scan before PL-4.
 */
final class ScriptImageService
{
    private const int RESERVATION_MINUTES = 20;

    private const int PRESIGN_MINUTES = 15;

    /**
     * Reserve an upload on a hook or a line and return the presigned PUT.
     *
     * @return array{upload_url: string, upload_id: string, expires_at: string, max_bytes: int}
     */
    public function init(Agency $agency, Brand $brand, Wave $wave, User $actor, string $scriptUlid, string $block, string $ownerUlid, string $mimeType, int $declaredBytes, string $filename): array
    {
        StrategyGuard::assertBrandWritable($brand);

        if (! array_key_exists($mimeType, ScriptImageStorage::MIME_TYPES)) {
            throw StrategyRuleViolation::scriptImageInvalid('Images must be JPG, PNG, WebP or GIF.');
        }
        if ($declaredBytes < 1) {
            throw StrategyRuleViolation::scriptImageInvalid('The file is empty.');
        }
        if ($declaredBytes > StrategyLimits::SCRIPT_IMAGE_TOTAL_BYTES) {
            throw StrategyRuleViolation::scriptImageLimitBytes(0, $declaredBytes);
        }

        $image = ScriptGuard::transaction($wave, function (Wave $locked) use ($agency, $brand, $actor, $scriptUlid, $block, $ownerUlid, $mimeType, $declaredBytes, $filename): WaveScriptImage {
            $script = ScriptGuard::lockedScript($locked, $scriptUlid);
            [$hookId, $lineId] = $this->owner($script, $block, $ownerUlid);

            $blockLive = ScriptImages::totals($script->id, $hookId !== null ? 'hook_id' : 'line_id', $hookId ?? $lineId);
            if ($blockLive['count'] >= StrategyLimits::IMAGES_PER_BLOCK) {
                throw StrategyRuleViolation::scriptImageLimitCount($block, $blockLive['count']);
            }
            $scriptLive = ScriptImages::totals($script->id);
            if ($scriptLive['bytes'] + $declaredBytes > StrategyLimits::SCRIPT_IMAGE_TOTAL_BYTES) {
                throw StrategyRuleViolation::scriptImageLimitBytes($scriptLive['bytes'], $declaredBytes);
            }

            $ulid = (string) Str::ulid();
            $last = WaveScriptImage::query()->where('script_id', $script->id)
                ->when($hookId !== null, static fn ($q) => $q->where('hook_id', $hookId))
                ->when($lineId !== null, static fn ($q) => $q->where('line_id', $lineId))
                ->max('position');

            return WaveScriptImage::query()->create([
                'ulid' => $ulid,
                'agency_id' => $locked->agency_id,
                'wave_id' => $locked->id,
                'script_id' => $script->id,
                'hook_id' => $hookId,
                'line_id' => $lineId,
                'position' => $last === null ? 0 : ((int) $last) + 1,
                'storage_key' => ScriptImageStorage::imageKey(ScriptImageStorage::scriptPrefix($agency, $brand, $locked, $script), $ulid, ScriptImageStorage::MIME_TYPES[$mimeType]),
                'original_filename' => Str::limit($filename, StrategyLimits::IMAGE_FILENAME, ''),
                'declared_bytes' => $declaredBytes,
                'status' => WaveScriptImage::PENDING,
                'expires_at' => now()->addMinutes(self::RESERVATION_MINUTES),
                'uploaded_by_user_id' => $actor->id,
            ]);
        });

        return $this->presign($image, $mimeType, $declaredBytes);
    }

    /**
     * Verify the stored object and make the image ready. Returns the image row.
     */
    public function complete(Brand $brand, Wave $wave, User $actor, string $scriptUlid, string $uploadId): WaveScriptImage
    {
        StrategyGuard::assertBrandWritable($brand);

        $script = WaveScript::query()->where('wave_id', $wave->id)->where('ulid', $scriptUlid)->whereNull('archived_at')->first();
        if ($script === null) {
            throw StrategyRuleViolation::conflict404();
        }
        $image = WaveScriptImage::query()->where('script_id', $script->id)->where('storage_key', $uploadId)->first();
        if ($image === null) {
            throw StrategyRuleViolation::scriptImageUnknownUpload();
        }
        if ($image->status === WaveScriptImage::READY) {
            throw StrategyRuleViolation::scriptImageAlreadyCompleted();
        }
        if ($image->expires_at !== null && $image->expires_at->isPast()) {
            throw StrategyRuleViolation::scriptImageUnknownUpload();
        }
        // Nothing stored yet: refuse but KEEP the reservation, so the same upload can be completed once the object is there.
        if (! ScriptImageStorage::disk()->exists($image->storage_key)) {
            throw StrategyRuleViolation::scriptImageInvalid('The uploaded file was not found. Upload it again, then complete.');
        }

        $verified = $this->verify($image);
        if (is_string($verified)) {
            $this->discard($image);

            throw StrategyRuleViolation::scriptImageInvalid($verified);
        }

        // The limits again, under the script lock, with the REAL size. A refusal is returned (not thrown) so that its rollback
        // does not undo the discard that follows.
        $outcome = ScriptGuard::transaction($wave, function (Wave $locked) use ($actor, $scriptUlid, $image, $verified): WaveScriptImage|StrategyRuleViolation {
            $script = ScriptGuard::lockedScript($locked, $scriptUlid);
            $fresh = WaveScriptImage::query()->where('script_id', $script->id)->whereKey($image->id)->first();
            if ($fresh === null) {
                return StrategyRuleViolation::scriptImageUnknownUpload();
            }
            if ($fresh->status === WaveScriptImage::READY) {
                return StrategyRuleViolation::scriptImageAlreadyCompleted();
            }

            $column = $fresh->hook_id !== null ? 'hook_id' : 'line_id';
            $block = ScriptImages::totals($script->id, $column, $fresh->hook_id ?? $fresh->line_id, $fresh->id);
            if ($block['count'] + 1 > StrategyLimits::IMAGES_PER_BLOCK) {
                return StrategyRuleViolation::scriptImageLimitCount($fresh->hook_id !== null ? 'hook' : 'line', $block['count']);
            }
            $total = ScriptImages::totals($script->id, exceptId: $fresh->id);
            if ($total['bytes'] + $verified['size'] > StrategyLimits::SCRIPT_IMAGE_TOTAL_BYTES) {
                return StrategyRuleViolation::scriptImageLimitBytes($total['bytes'], $verified['size']);
            }

            $last = WaveScriptImage::query()->where('script_id', $script->id)->where('status', WaveScriptImage::READY)->where($column, $fresh->hook_id ?? $fresh->line_id)->max('position');
            $fresh->forceFill([
                'mime_type' => $verified['mime'],
                'size_bytes' => $verified['size'],
                'status' => WaveScriptImage::READY,
                'expires_at' => null,
                'position' => $last === null ? 0 : ((int) $last) + 1,
            ])->save();
            $this->touch($script, $actor);

            Audit::log(
                action: AuditAction::WaveScriptImageAdded,
                actor: $actor,
                subject: $fresh,
                // Literal keys on purpose. Ids, the block kind, the type and the size: never the file name.
                metadata: ['wave' => $locked->ulid, 'market' => $this->marketUlid($script), 'script' => $script->ulid, 'number' => $script->number, 'block' => $fresh->hook_id !== null ? 'hook' : 'line', 'owner' => $this->ownerUlid($fresh), 'image' => $fresh->ulid, 'mime_type' => $verified['mime'], 'size_bytes' => $verified['size']],
                agencyId: $locked->agency_id,
            );

            return $fresh;
        });

        if ($outcome instanceof StrategyRuleViolation) {
            // Refused after the object was stored: object first, then the reservation.
            $this->discard($image);

            throw $outcome;
        }

        // After the commit: the worker (or the sync queue in tests) finds a committed, ready row.
        GenerateScriptImageThumbnail::dispatch($outcome->id);

        return $outcome->refresh();
    }

    /** Delete one image: the object first (a missing object counts as deleted), then the row. */
    public function delete(Brand $brand, Wave $wave, User $actor, string $scriptUlid, string $imageUlid): void
    {
        StrategyGuard::assertBrandWritable($brand);

        ScriptGuard::transaction($wave, function (Wave $locked) use ($actor, $scriptUlid, $imageUlid): void {
            $script = ScriptGuard::lockedScript($locked, $scriptUlid);
            $image = WaveScriptImage::query()->where('script_id', $script->id)->where('ulid', $imageUlid)->first();
            if ($image === null) {
                throw StrategyRuleViolation::conflict404();
            }
            $size = (int) ($image->size_bytes ?? 0);
            $wasReady = $image->status === WaveScriptImage::READY;
            $block = $image->hook_id !== null ? 'hook' : 'line';
            $owner = $this->ownerUlid($image);

            ScriptImages::purge(new Collection([$image]));
            $this->touch($script, $actor);

            if ($wasReady) {
                Audit::log(
                    action: AuditAction::WaveScriptImageRemoved,
                    actor: $actor,
                    subject: $image,
                    metadata: ['wave' => $locked->ulid, 'market' => $this->marketUlid($script), 'script' => $script->ulid, 'number' => $script->number, 'block' => $block, 'owner' => $owner, 'image' => $image->ulid, 'mime_type' => (string) $image->mime_type, 'size_bytes' => $size],
                    agencyId: $locked->agency_id,
                );
            }
        });
    }

    /**
     * Reorder the READY images of one block: `$ids` must be exactly the block's ready images (complete list), otherwise
     * `order_mismatch` and nothing is written.
     *
     * @param  list<string>  $ids
     */
    public function reorder(Brand $brand, Wave $wave, User $actor, string $scriptUlid, string $block, string $ownerUlid, array $ids): WaveScript
    {
        StrategyGuard::assertBrandWritable($brand);

        return ScriptGuard::transaction($wave, function (Wave $locked) use ($actor, $scriptUlid, $block, $ownerUlid, $ids): WaveScript {
            $script = ScriptGuard::lockedScript($locked, $scriptUlid);
            [$hookId, $lineId] = $this->owner($script, $block, $ownerUlid);

            $ready = ScriptImages::ofBlock($script->id, $hookId, $lineId)->where('status', WaveScriptImage::READY)->values();
            $current = array_values($ready->map(static fn ($m): string => (string) $m->ulid)->all());
            if (! ScriptOrder::sameSet($current, $ids)) {
                throw StrategyRuleViolation::scriptOrderMismatch('images', $current);
            }
            $byUlid = $ready->keyBy('ulid');
            foreach ($ids as $position => $ulid) {
                $row = $byUlid->get($ulid);
                if ($row !== null && $row->position !== $position) {
                    $row->forceFill(['position' => $position])->save();
                }
            }
            $this->touch($script, $actor);
            Audit::log(
                action: AuditAction::WaveScriptImageReordered,
                actor: $actor,
                subject: $script,
                metadata: ['wave' => $locked->ulid, 'market' => $this->marketUlid($script), 'script' => $script->ulid, 'number' => $script->number, 'block' => $block, 'owner' => $ownerUlid, 'count' => count($ids)],
                agencyId: $locked->agency_id,
            );

            return $script;
        });
    }

    /**
     * The owner of a block: `[hookId, null]` or `[null, lineId]`. An unknown block kind is a 404; a hook of another script is a 404;
     * a deleted line is the "this line was deleted" answer.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function owner(WaveScript $script, string $block, string $ulid): array
    {
        if ($block === 'hook') {
            $hook = WaveScriptHook::query()->where('script_id', $script->id)->where('ulid', $ulid)->first();
            if ($hook === null) {
                throw StrategyRuleViolation::conflict404();
            }

            return [$hook->id, null];
        }
        if ($block === 'line') {
            $line = WaveScriptLine::query()->where('script_id', $script->id)->where('ulid', $ulid)->first();
            if ($line === null) {
                throw StrategyRuleViolation::scriptLineDeleted();
            }

            return [null, $line->id];
        }

        throw StrategyRuleViolation::conflict404();
    }

    private function ownerUlid(WaveScriptImage $image): string
    {
        return $image->hook_id !== null
            ? (string) WaveScriptHook::query()->whereKey($image->hook_id)->value('ulid')
            : (string) WaveScriptLine::query()->whereKey($image->line_id)->value('ulid');
    }

    private function marketUlid(WaveScript $script): string
    {
        return (string) WaveMarket::query()->whereKey($script->market_id)->value('ulid');
    }

    private function touch(WaveScript $script, User $actor): void
    {
        $script->revision++;
        $script->updated_by_user_id = $actor->id;
        $script->save();
    }

    /**
     * @return array{mime: string, size: int}|string the verified facts, or the reason it is refused
     */
    private function verify(WaveScriptImage $image): array|string
    {
        $disk = ScriptImageStorage::disk();
        if (! $disk->exists($image->storage_key)) {
            return 'The uploaded file was not found. Start the upload again.';
        }
        $size = (int) $disk->size($image->storage_key);
        if ($size <= 0) {
            return 'The uploaded file is empty.';
        }
        if ($size > $image->declared_bytes) {
            return 'The uploaded file is larger than the size it was reserved for.';
        }

        $head = ConceptImageStorage::head($image->storage_key);
        $sniff = $head === null ? null : ScriptImageStorage::sniff($head);
        if ($sniff === null) {
            return 'The file is not a JPG, PNG, WebP or GIF image.';
        }
        $extension = strtolower(pathinfo($image->storage_key, PATHINFO_EXTENSION));
        if (ScriptImageStorage::MIME_TYPES[$sniff['mime']] !== $extension) {
            return 'The file is not the type it was declared as.';
        }
        $stored = $disk->mimeType($image->storage_key);
        if (is_string($stored) && $stored !== '' && $stored !== 'application/octet-stream' && $stored !== $sniff['mime']) {
            return 'The stored file type does not match its content.';
        }

        return ['mime' => $sniff['mime'], 'size' => $size];
    }

    /** A refused upload: its object goes, then its reservation. */
    private function discard(WaveScriptImage $image): void
    {
        if (ConceptImageStorage::deleteAll(ScriptImageStorage::keysOf($image))) {
            $image->delete();
        }
    }

    /**
     * @return array{upload_url: string, upload_id: string, expires_at: string, max_bytes: int}
     */
    private function presign(WaveScriptImage $image, string $mimeType, int $declaredBytes): array
    {
        $disk = ScriptImageStorage::disk();
        $expires = now()->addMinutes(self::PRESIGN_MINUTES)->toIso8601String();

        if (! $disk instanceof AwsS3V3Adapter) {
            // Local-dev / test fallback (the concept images precedent).
            return ['upload_url' => '/_test/presigned-upload/'.urlencode($image->storage_key), 'upload_id' => $image->storage_key, 'expires_at' => $expires, 'max_bytes' => StrategyLimits::SCRIPT_IMAGE_TOTAL_BYTES];
        }

        /** @var S3Client $client */
        $client = $disk->getClient();
        $command = $client->getCommand('PutObject', [
            'Bucket' => $disk->getConfig()['bucket'],
            'Key' => $image->storage_key,
            'ContentType' => $mimeType,
            'ContentLength' => $declaredBytes,
        ]);

        return [
            'upload_url' => (string) $client->createPresignedRequest($command, '+'.self::PRESIGN_MINUTES.' minutes')->getUri(),
            'upload_id' => $image->storage_key,
            'expires_at' => $expires,
            'max_bytes' => StrategyLimits::SCRIPT_IMAGE_TOTAL_BYTES,
        ];
    }
}
