<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Selection;

/**
 * What an erasure WOULD do, as numbers: selection id, table, disposition, stage
 * and a row count. No email, no name, no id of a row, no ip: the report is safe
 * to print, paste into a ticket or store in the ledger.
 */
final readonly class DryRunReport
{
    /**
     * @param  list<array{id: string, table: string, disposition: string, stage: string, description: string, count: int}>  $lines
     * @param  array<string, int>  $subject  SubjectSet::summary()
     */
    public function __construct(
        public string $userUlid,
        public array $subject,
        public array $lines,
    ) {}

    /** @return array<string, int> selection id => rows that would change */
    public function counts(): array
    {
        $out = [];
        foreach ($this->lines as $line) {
            $out[$line['id']] = $line['count'];
        }
        ksort($out);

        return $out;
    }

    public function total(): int
    {
        return array_sum($this->counts());
    }

    /**
     * A hash of what this run would do. Execute (Stage 5) recomputes it fresh
     * and refuses on a mismatch, so an old approval cannot be run against new
     * data. Stages 4f and 4g add the S3 key list and the vendor-call list to
     * the hashed material.
     */
    public function digest(): string
    {
        return hash('sha256', json_encode([
            'user' => $this->userUlid,
            'subject' => $this->subject,
            'counts' => $this->counts(),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'user_ulid' => $this->userUlid,
            'subject' => $this->subject,
            'lines' => $this->lines,
            'digest' => $this->digest(),
        ];
    }
}
