<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Run;

use App\Modules\Identity\Models\User;

/** What a reapply would do: who, per-subject plans, and the entries it leaves alone. Counts and ulids only. */
final readonly class ReapplyPlan
{
    /** @param  list<array{user: User, plan: ErasurePlan}>  $subjects */
    public function __construct(
        public int $mirrorEntries,
        public int $unknownUsers,
        public int $alreadyErased,
        public array $subjects,
    ) {}

    public function digest(): string
    {
        $digests = array_map(fn (array $s): string => $s['user']->ulid.':'.$s['plan']->digest(), $this->subjects);
        sort($digests);

        return hash('sha256', json_encode($digests, JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    public function blockers(): array
    {
        $out = [];
        foreach ($this->subjects as $subject) {
            foreach ($subject['plan']->blockers() as $blocker) {
                $out[] = $subject['user']->ulid.': '.$blocker;
            }
        }

        return $out;
    }
}
