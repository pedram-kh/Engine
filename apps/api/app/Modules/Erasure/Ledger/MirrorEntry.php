<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Ledger;

/** One mirrored erasure: who (the pseudonymous ulid) and when. */
final readonly class MirrorEntry
{
    public function __construct(public string $userUlid, public string $completedAt) {}
}
