<?php

declare(strict_types=1);

namespace App\Core\Storage;

use Exception;

/**
 * Thrown when an object-storage delete reports failure.
 *
 * The {@see StorageWriteFailedException} sibling for the opposite direction.
 * Every disk in `config/filesystems.php` is `'throw' => false`, so an
 * unreachable bucket makes `Storage::delete()` return `false` rather than
 * raise — silence that would otherwise let a caller soft-delete a row while
 * the S3 object behind it stays live and orphaned-but-invisible.
 *
 * AH-091's delete flow (AgencyFileUploadService::delete()) is object-first:
 * it calls `Storage::delete()` and inspects the boolean BEFORE it
 * soft-deletes the row. A `false` here throws this exception instead, so the
 * row is left fully visible — orphaned bytes with a visible row beats an
 * invisible row over live bytes (the ratified failure shape, D5).
 */
final class StorageDeleteFailedException extends Exception
{
    public static function forPath(string $disk, string $path): self
    {
        return new self(sprintf(
            'Storage disk [%s] reported a failed delete for [%s].',
            $disk,
            $path,
        ));
    }
}
