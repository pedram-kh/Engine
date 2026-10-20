<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Ledger;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * {@see LedgerMirror} on the private `erasure-ledger/` prefix of the media disk
 * (ruling Q1): one small JSON object per completed erasure, keyed by the user's
 * ulid, holding that ulid and a timestamp.
 *
 * The application role needs PutObject and GetObject/ListBucket on the prefix
 * and nothing else; bucket versioning (and Object Lock, if the infrastructure
 * owner adds it) is what stops the same mistake that needs a restore from
 * wiping it. This class has no delete, by design.
 */
final class DiskLedgerMirror implements LedgerMirror
{
    public const string PREFIX = 'erasure-ledger';

    private const string ULID = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    public function record(string $userUlid, CarbonInterface $completedAt): void
    {
        $key = $this->keyFor($userUlid);
        $disk = Storage::disk('media');

        try {
            if ($disk->exists($key)) {
                return;
            }
            $written = $disk->put($key, json_encode(['user_ulid' => $userUlid, 'completed_at' => $completedAt->toIso8601String()], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            throw MirrorWriteFailed::forEntry();
        }

        if ($written !== true) {
            throw MirrorWriteFailed::forEntry();
        }
    }

    public function entries(): array
    {
        $disk = Storage::disk('media');
        $keys = array_map(strval(...), $disk->files(self::PREFIX));
        sort($keys);

        $entries = [];
        foreach ($keys as $key) {
            $name = basename($key, '.json');
            if (preg_match(self::ULID, $name) !== 1 || ! str_ends_with($key, '.json')) {
                continue;
            }
            $decoded = json_decode((string) $disk->get($key), true);
            // The name and the content must agree: a stray or edited object is not an instruction to erase anyone.
            if (! is_array($decoded) || ($decoded['user_ulid'] ?? null) !== $name || ! is_string($decoded['completed_at'] ?? null)) {
                continue;
            }
            $entries[] = new MirrorEntry($name, $decoded['completed_at']);
        }

        return $entries;
    }

    private function keyFor(string $userUlid): string
    {
        if (preg_match(self::ULID, $userUlid) !== 1) {
            throw MirrorWriteFailed::forEntry();
        }

        return self::PREFIX.'/'.$userUlid.'.json';
    }
}
