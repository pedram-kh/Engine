<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptImage;
use App\Modules\Strategy\StrategyLimits;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * The image rows and objects of a script, as the OTHER writers need them (PL-3.2b): the hook and line services (delete and
 * duplicate a block), {@see ScriptArchive} (permanent delete of a script) and {@see ScriptImageService}. Called ONLY from inside a
 * {@see ScriptGuard::transaction()} that already holds the script row lock, so it never opens a transaction of its own. The lock is
 * what makes "delete the objects, then the rows" safe: an upload reserves under the same script lock, so no image can appear
 * between the two steps.
 */
final class ScriptImages
{
    /**
     * Every image row (any status) of one block: a hook (`$hookId`) or a line (`$lineId`), in display order.
     *
     * @return Collection<int, WaveScriptImage>
     */
    public static function ofBlock(int $scriptId, ?int $hookId, ?int $lineId): Collection
    {
        return WaveScriptImage::query()->where('script_id', $scriptId)
            ->when($hookId !== null, static fn ($q) => $q->where('hook_id', $hookId))
            ->when($lineId !== null, static fn ($q) => $q->where('line_id', $lineId))
            ->orderBy('position')->orderBy('id')->get();
    }

    /**
     * Ready rows (their real size) plus live reservations (their declared size): what counts against the limits. `$exceptId`
     * leaves one row out (the upload that is being verified counts itself separately).
     *
     * @return array{count: int, bytes: int}
     */
    public static function totals(int $scriptId, ?string $ownerColumn = null, ?int $ownerId = null, ?int $exceptId = null): array
    {
        $row = WaveScriptImage::query()
            ->where('script_id', $scriptId)
            ->when($ownerColumn !== null, static fn ($q) => $q->where((string) $ownerColumn, $ownerId))
            ->when($exceptId !== null, static fn ($q) => $q->where('id', '!=', $exceptId))
            ->where(static function ($q): void {
                $q->where('status', WaveScriptImage::READY)->orWhere(static function ($q2): void {
                    $q2->where('status', WaveScriptImage::PENDING)->where('expires_at', '>', now());
                });
            })
            ->selectRaw('count(*) as n, coalesce(sum(case when status = ? then size_bytes else declared_bytes end), 0) as b', [WaveScriptImage::READY])
            ->first();

        return ['count' => (int) ($row?->getAttribute('n') ?? 0), 'bytes' => (int) ($row?->getAttribute('b') ?? 0)];
    }

    /**
     * Delete the OBJECTS of these images first; only when every delete is confirmed (a missing object is a success) are the rows
     * deleted. A failure throws before any row is touched, so nothing in the database changes and the person retries.
     *
     * @param  Collection<int, WaveScriptImage>  $images
     */
    public static function purge(Collection $images): int
    {
        $keys = [];
        foreach ($images as $image) {
            array_push($keys, ...ScriptImageStorage::keysOf($image));
        }
        if (! ConceptImageStorage::deleteAll($keys)) {
            throw StrategyRuleViolation::scriptImageDeleteFailed();
        }
        foreach ($images as $image) {
            $image->delete();
        }

        return $images->count();
    }

    /** Delete every image of one block, objects first. Returns how many. */
    public static function purgeBlock(int $scriptId, ?int $hookId, ?int $lineId): int
    {
        return self::purge(self::ofBlock($scriptId, $hookId, $lineId));
    }

    /** Delete every image of a script (permanent delete), objects first. Returns how many. */
    public static function purgeScript(int $scriptId): int
    {
        return self::purge(WaveScriptImage::query()->where('script_id', $scriptId)->orderBy('id')->get());
    }

    /**
     * Copy the READY images of one block to another block of the same script (a duplicate): a server-side copy to NEW keys, never
     * a shared object. The copies must fit the script's 300 MB total (checked first, nothing is copied otherwise). A failed copy
     * deletes the copies made so far and throws, so no copy is left half made.
     *
     * @return int how many images were copied
     */
    public static function copyBlock(WaveScript $script, ?int $fromHookId, ?int $fromLineId, ?int $toHookId, ?int $toLineId, ?int $actorId): int
    {
        $sources = self::ofBlock($script->id, $fromHookId, $fromLineId)->where('status', WaveScriptImage::READY)->values();
        if ($sources->isEmpty()) {
            return 0;
        }
        $live = self::totals($script->id);
        $asked = (int) $sources->sum('size_bytes');
        if ($live['bytes'] + $asked > StrategyLimits::SCRIPT_IMAGE_TOTAL_BYTES) {
            throw StrategyRuleViolation::scriptImageLimitBytes($live['bytes'], $asked);
        }

        $madeKeys = [];
        $madeRows = [];

        try {
            foreach ($sources as $source) {
                $ulid = (string) Str::ulid();
                $prefix = ScriptImageStorage::prefixOfKey($source->storage_key);
                $extension = strtolower(pathinfo($source->storage_key, PATHINFO_EXTENSION));
                $key = ScriptImageStorage::imageKey($prefix, $ulid, $extension);
                if (! ConceptImageStorage::copy($source->storage_key, $key)) {
                    throw StrategyRuleViolation::scriptImageCopyFailed();
                }
                $madeKeys[] = $key;

                $thumb = null;
                if ($source->thumb_key !== null) {
                    $thumbKey = ScriptImageStorage::thumbKey($prefix, $ulid);
                    if (! ConceptImageStorage::copy($source->thumb_key, $thumbKey)) {
                        throw StrategyRuleViolation::scriptImageCopyFailed();
                    }
                    $madeKeys[] = $thumbKey;
                    $thumb = $thumbKey;
                }

                $madeRows[] = WaveScriptImage::query()->create([
                    'ulid' => $ulid, 'agency_id' => $source->agency_id, 'wave_id' => $source->wave_id, 'script_id' => $source->script_id,
                    'hook_id' => $toHookId, 'line_id' => $toLineId, 'position' => $source->position,
                    'storage_key' => $key, 'thumb_key' => $thumb, 'original_filename' => $source->original_filename,
                    'mime_type' => $source->mime_type, 'declared_bytes' => $source->declared_bytes, 'size_bytes' => $source->size_bytes,
                    'status' => WaveScriptImage::READY, 'expires_at' => null, 'uploaded_by_user_id' => $actorId,
                ]);
            }
        } catch (\Throwable $e) {
            // The copies made so far go (objects first); their rows only when the database is still usable.
            ConceptImageStorage::deleteAll($madeKeys);
            if ($e instanceof StrategyRuleViolation) {
                foreach ($madeRows as $row) {
                    $row->delete();
                }
            }

            throw $e;
        }

        return count($madeRows);
    }
}
