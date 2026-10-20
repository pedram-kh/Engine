<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Files;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * {@see ObjectStore} on the `media` disk, where avatars, portfolio files and
 * message attachments live (the disk every upload service names explicitly).
 *
 * `config('erasure.purge_object_versions')` (default true) also deletes every
 * stored version when the disk is S3. If the app role may not list or delete
 * versions the call fails and the run stops before any database change: failing
 * closed is the point, because a delete marker alone leaves the file readable.
 */
final class MediaDiskObjectStore implements ObjectStore
{
    public function list(string $prefix): array
    {
        return array_values(array_map(strval(...), Storage::disk('media')->allFiles($prefix)));
    }

    public function delete(string $key): void
    {
        $disk = Storage::disk('media');

        try {
            $disk->delete($key);

            if ($disk instanceof AwsS3V3Adapter && config('erasure.purge_object_versions') === true) {
                $bucket = $disk->getConfig()['bucket'] ?? null;
                if (is_string($bucket) && $bucket !== '') {
                    (new S3VersionPurger($disk->getClient(), $bucket))->purge($key);
                }
            }
        } catch (Throwable $e) {
            throw ObjectDeletionFailed::forObject($e);
        }
    }
}
