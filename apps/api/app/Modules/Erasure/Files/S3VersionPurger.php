<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Files;

use Aws\S3\S3Client;

/**
 * Removes every stored version and delete marker of ONE exact key.
 *
 * A plain DELETE on a versioned bucket only adds a delete marker: the old bytes
 * (a face, an identity document) stay readable by version id. So the erasure
 * lists the versions of the key and deletes each by id.
 *
 * `ListObjectVersions` filters by PREFIX, so a prefix of `a/b.jpg` also returns
 * `a/b.jpg.bak` and `a/b.jpgx`. Only entries whose `Key` equals the key exactly
 * are deleted; anything else is a different object and is left alone.
 */
final class S3VersionPurger
{
    public function __construct(private readonly S3Client $client, private readonly string $bucket) {}

    /** @return int how many versions and markers were deleted */
    public function purge(string $key): int
    {
        $deleted = 0;
        $keyMarker = null;
        $versionIdMarker = null;

        do {
            $args = ['Bucket' => $this->bucket, 'Prefix' => $key];
            if ($keyMarker !== null) {
                $args['KeyMarker'] = $keyMarker;
                $args['VersionIdMarker'] = $versionIdMarker;
            }
            $page = $this->client->listObjectVersions($args);

            $entries = array_merge((array) ($page['Versions'] ?? []), (array) ($page['DeleteMarkers'] ?? []));
            foreach ($entries as $entry) {
                if (! is_array($entry) || ($entry['Key'] ?? null) !== $key || ! isset($entry['VersionId'])) {
                    continue;
                }
                $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key, 'VersionId' => $entry['VersionId']]);
                $deleted++;
            }

            $truncated = (bool) ($page['IsTruncated'] ?? false);
            $keyMarker = $truncated ? ($page['NextKeyMarker'] ?? null) : null;
            $versionIdMarker = $truncated ? ($page['NextVersionIdMarker'] ?? null) : null;
        } while ($truncated && $keyMarker !== null);

        return $deleted;
    }
}
