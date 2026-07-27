<?php

declare(strict_types=1);

namespace App\Core\Storage;

use Exception;

/**
 * Thrown when an object-storage write reports failure.
 *
 * Deliberately NOT a {@see \RuntimeException}. The upload controllers catch
 * `RuntimeException` to turn CONTENT rejections — wrong MIME, oversize, an
 * undecodable raster — into a 422 the uploader can act on. A failed `put()`
 * is the opposite kind of event: the request was valid and the SERVER could
 * not persist it. Answering 422 would blame the user for an outage.
 *
 * Answering 200 is worse, and is what an unchecked `put()` return produces:
 * every object-storage disk in this app is configured `'throw' => false`, so
 * an unreachable bucket makes `put()` return `false` rather than raise. The
 * write silently evaporates, the column is still assigned, and the row ends
 * up pointing at a key with no object behind it.
 *
 * Escaping both catch blocks is the point: the surrounding transaction rolls
 * back (so the column is never set to a dangling key) and the failure surfaces
 * as a reported 500 instead of a green response over a lost file.
 */
final class StorageWriteFailedException extends Exception
{
    public static function forPath(string $disk, string $path): self
    {
        return new self(sprintf(
            'Storage disk [%s] reported a failed write for [%s].',
            $disk,
            $path,
        ));
    }
}
