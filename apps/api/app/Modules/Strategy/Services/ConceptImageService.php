<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Jobs\GenerateConceptImageThumbnail;
use App\Modules\Strategy\Models\WaveConceptImage;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Models\Wave;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reference images of a concept (PL-2.3 plan sections 1.5 and 1.7, rulings C4, Q9, Q12).
 *
 * The 10-image and 300 MB limits are a RESERVATION made at `init`, under a row lock on the concept: parallel uploads
 * each see the others' reservations. `complete` reads the stored object's own MIME and size and checks the first bytes
 * decode as a JPEG, PNG or WebP image that agrees with the key's extension; anything else is refused and its object and
 * reservation deleted. Reads go through the API only (no presigned GET is ever returned).
 */
final class ConceptImageService
{
    private const int RESERVATION_MINUTES = 20;

    private const int PRESIGN_MINUTES = 15;

    public function __construct(private readonly ConceptService $concepts) {}

    /**
     * @return array{upload_url: string, upload_id: string, expires_at: string, max_bytes: int}
     */
    public function init(Agency $agency, Brand $brand, Wave $wave, string $conceptUlid, User $actor, string $mimeType, int $declaredBytes, string $filename): array
    {
        StrategyGuard::assertBrandWritable($brand);

        if (! array_key_exists($mimeType, ConceptImageStorage::MIME_TYPES)) {
            throw StrategyRuleViolation::imageInvalid('Images must be JPG, PNG or WebP.');
        }
        if ($declaredBytes < 1) {
            throw StrategyRuleViolation::imageInvalid('The file is empty.');
        }
        if ($declaredBytes > StrategyLimits::IMAGE_TOTAL_BYTES) {
            throw StrategyRuleViolation::imageLimitBytes(0, $declaredBytes);
        }

        $image = DB::transaction(function () use ($agency, $brand, $wave, $conceptUlid, $actor, $mimeType, $declaredBytes, $filename): WaveConceptImage {
            // The concept row lock: the first statement after the checks, so parallel inits queue here.
            $concept = $this->concepts->lockedConcept($wave, $conceptUlid);

            $live = $this->liveTotals($concept->id);
            if ($live['count'] >= StrategyLimits::IMAGES_PER_CONCEPT) {
                throw StrategyRuleViolation::imageLimitCount($live['count']);
            }
            if ($live['bytes'] + $declaredBytes > StrategyLimits::IMAGE_TOTAL_BYTES) {
                throw StrategyRuleViolation::imageLimitBytes($live['bytes'], $declaredBytes);
            }

            $ulid = (string) Str::ulid();

            return WaveConceptImage::query()->create([
                'ulid' => $ulid,
                'agency_id' => $wave->agency_id,
                'wave_id' => $wave->id,
                'market_id' => $concept->market_id,
                'concept_id' => $concept->id,
                'storage_key' => ConceptImageStorage::imageKey(ConceptImageStorage::conceptPrefix($agency, $brand, $wave, $concept), $ulid, ConceptImageStorage::MIME_TYPES[$mimeType]),
                'original_filename' => Str::limit($filename, StrategyLimits::IMAGE_FILENAME, ''),
                'declared_bytes' => $declaredBytes,
                'status' => WaveConceptImage::PENDING,
                'expires_at' => now()->addMinutes(self::RESERVATION_MINUTES),
                'position' => $live['count'] + 1,
                'uploaded_by_user_id' => $actor->id,
            ]);
        });

        return $this->presign($image, $mimeType, $declaredBytes);
    }

    /**
     * @return array{image: WaveConceptImage, reset_to_awaiting: bool}
     */
    public function complete(Brand $brand, Wave $wave, string $conceptUlid, User $actor, string $uploadId): array
    {
        StrategyGuard::assertBrandWritable($brand);

        $concept = $this->concepts->find($wave, $conceptUlid);
        $image = WaveConceptImage::query()->where('concept_id', $concept->id)->where('storage_key', $uploadId)->first();
        if ($image === null) {
            throw StrategyRuleViolation::imageUnknownUpload();
        }
        if ($image->status === WaveConceptImage::READY) {
            throw StrategyRuleViolation::imageAlreadyCompleted();
        }
        if ($image->expires_at !== null && $image->expires_at->isPast()) {
            throw StrategyRuleViolation::imageUnknownUpload();
        }

        // Nothing stored yet (the browser has not finished, or the PUT failed): refuse but KEEP the reservation, so the
        // same upload can be completed once the object is there (or the sweeper removes it after it expires).
        if (! ConceptImageStorage::disk()->exists($image->storage_key)) {
            throw StrategyRuleViolation::imageInvalid('The uploaded file was not found. Upload it again, then complete.');
        }

        // The object, as it is stored: its own size and MIME, and a look at the first bytes. The request says nothing.
        $verified = $this->verify($image);
        if (is_string($verified)) {
            $this->discard($image);

            throw StrategyRuleViolation::imageInvalid($verified);
        }

        $reset = false;
        DB::transaction(function () use ($wave, $conceptUlid, $actor, $image, $verified, &$reset): void {
            $locked = $this->concepts->lockedConcept($wave, $conceptUlid);
            $fresh = WaveConceptImage::query()->whereKey($image->id)->first();
            if ($fresh === null) {
                throw StrategyRuleViolation::imageUnknownUpload();
            }
            if ($fresh->status === WaveConceptImage::READY) {
                throw StrategyRuleViolation::imageAlreadyCompleted();
            }

            // The real size is never above the reserved one (checked in verify), so the reservation already covers it.
            $fresh->forceFill([
                'mime_type' => $verified['mime'],
                'size_bytes' => $verified['size'],
                'status' => WaveConceptImage::READY,
                'expires_at' => null,
            ])->save();
            $image->setRawAttributes($fresh->getAttributes(), true);

            $reset = ConceptService::resetIfApproved($locked);
            Audit::log(
                action: AuditAction::WaveConceptImageAdded,
                actor: $actor,
                subject: $fresh,
                metadata: ['wave' => $wave->ulid, 'concept' => $locked->ulid, 'image' => $fresh->ulid, 'mime_type' => $verified['mime'], 'size_bytes' => $verified['size'], 'reset_to_awaiting' => $reset],
                agencyId: $wave->agency_id,
            );
        });

        // After the transaction: the worker (or the sync queue in tests) finds a committed, ready row.
        GenerateConceptImageThumbnail::dispatch($image->id);

        return ['image' => $image->refresh(), 'reset_to_awaiting' => $reset];
    }

    /** Object first (a missing object counts as deleted), then the row. @return bool reset_to_awaiting */
    public function delete(Brand $brand, Wave $wave, string $conceptUlid, string $imageUlid, User $actor): bool
    {
        StrategyGuard::assertBrandWritable($brand);

        $concept = $this->concepts->find($wave, $conceptUlid);
        $image = WaveConceptImage::query()->where('concept_id', $concept->id)->where('ulid', $imageUlid)->first();
        if ($image === null) {
            throw StrategyRuleViolation::conceptNotFound();
        }
        if (! ConceptImageStorage::deleteAll(ConceptImageStorage::keysOf($image))) {
            throw StrategyRuleViolation::imageDeleteFailed();
        }

        return DB::transaction(function () use ($wave, $conceptUlid, $image, $actor): bool {
            StrategyGuard::lockWave($wave);
            $locked = $this->concepts->lockedConcept($wave, $conceptUlid);
            $size = (int) ($image->size_bytes ?? 0);
            $wasReady = $image->status === WaveConceptImage::READY;
            $image->delete();

            $reset = $wasReady && ConceptService::resetIfApproved($locked);
            if ($wasReady) {
                Audit::log(
                    action: AuditAction::WaveConceptImageRemoved,
                    actor: $actor,
                    subject: $image,
                    metadata: ['wave' => $wave->ulid, 'concept' => $locked->ulid, 'image' => $image->ulid, 'size_bytes' => $size, 'reset_to_awaiting' => $reset],
                    agencyId: $wave->agency_id,
                );
            }

            return $reset;
        });
    }

    /** @return array{count: int, bytes: int} ready rows (their real size) plus live reservations (their declared size) */
    private function liveTotals(int $conceptId): array
    {
        $row = WaveConceptImage::query()
            ->where('concept_id', $conceptId)
            ->where(static function ($q): void {
                $q->where('status', WaveConceptImage::READY)->orWhere(static function ($q2): void {
                    $q2->where('status', WaveConceptImage::PENDING)->where('expires_at', '>', now());
                });
            })
            ->selectRaw('count(*) as n, coalesce(sum(case when status = ? then size_bytes else declared_bytes end), 0) as b', [WaveConceptImage::READY])
            ->first();

        return ['count' => (int) ($row?->getAttribute('n') ?? 0), 'bytes' => (int) ($row?->getAttribute('b') ?? 0)];
    }

    /**
     * @return array{mime: string, size: int}|string the verified facts, or the reason it is refused
     */
    private function verify(WaveConceptImage $image): array|string
    {
        $disk = ConceptImageStorage::disk();
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
        $sniff = $head === null ? null : ConceptImageStorage::sniff($head);
        if ($sniff === null) {
            return 'The file is not a JPG, PNG or WebP image.';
        }
        $extension = strtolower(pathinfo($image->storage_key, PATHINFO_EXTENSION));
        if (ConceptImageStorage::MIME_TYPES[$sniff['mime']] !== $extension) {
            return 'The file is not the type it was declared as.';
        }
        $stored = $disk->mimeType($image->storage_key);
        if (is_string($stored) && $stored !== '' && $stored !== 'application/octet-stream' && $stored !== $sniff['mime']) {
            return 'The stored file type does not match its content.';
        }

        return ['mime' => $sniff['mime'], 'size' => $size];
    }

    /** A refused upload: its object goes, then its reservation. */
    private function discard(WaveConceptImage $image): void
    {
        if (ConceptImageStorage::deleteAll(ConceptImageStorage::keysOf($image))) {
            $image->delete();
        }
    }

    /**
     * @return array{upload_url: string, upload_id: string, expires_at: string, max_bytes: int}
     */
    private function presign(WaveConceptImage $image, string $mimeType, int $declaredBytes): array
    {
        $disk = ConceptImageStorage::disk();
        $expires = now()->addMinutes(self::PRESIGN_MINUTES)->toIso8601String();

        if (! $disk instanceof AwsS3V3Adapter) {
            // Local-dev / test fallback (the brand files precedent).
            return ['upload_url' => '/_test/presigned-upload/'.urlencode($image->storage_key), 'upload_id' => $image->storage_key, 'expires_at' => $expires, 'max_bytes' => StrategyLimits::IMAGE_TOTAL_BYTES];
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
            'max_bytes' => StrategyLimits::IMAGE_TOTAL_BYTES,
        ];
    }
}
