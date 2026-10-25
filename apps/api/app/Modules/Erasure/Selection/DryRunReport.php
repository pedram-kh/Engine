<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Selection;

use App\Modules\Identity\Services\CredentialRevoker;

/**
 * What an erasure WOULD do, as numbers: selection id, table, disposition, stage
 * and a row count. No email, no name, no id of a row, no ip: the report is safe
 * to print, paste into a ticket or store in the ledger.
 */
final readonly class DryRunReport
{
    /**
     * @param  list<array{id: string, table: string, disposition: string, stage: string, description: string, count: int, read: int}>  $lines
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

    /**
     * What the run has to read to do its writes, in rows and in batches of `$batch`.
     * Reported, never hashed: it moves with other people's activity and must not
     * change the digest.
     *
     * @return array{rows: int, batches: int, largest: string, largest_rows: int}
     */
    public function cost(int $batch): array
    {
        $rows = 0;
        $batches = 0;
        $largest = '';
        $largestRows = 0;
        foreach ($this->lines as $line) {
            if ($line['read'] === 0) {
                continue;
            }
            $rows += $line['read'];
            $batches += intdiv($line['read'] + $batch - 1, $batch);
            if ($line['read'] > $largestRows) {
                $largest = $line['id'];
                $largestRows = $line['read'];
            }
        }

        return ['rows' => $rows, 'batches' => $batches, 'largest' => $largest, 'largest_rows' => $largestRows];
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
            'counts' => $this->hashedCounts(),
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

    /**
     * The counts that go into the digest: every line EXCEPT the credential tables.
     *
     * The claim revokes every session and token in its own transaction (AH-104c C2-0), and the
     * execute recomputes the digest after the claim, so a digest that counted those rows would
     * never match the dry-run's for an account that had any. They are still printed (the
     * operator sees them), and the erasure still deletes whatever is left; what they cannot
     * do is stand for "the data changed": the person signing in or a token refreshing is not a
     * reason to refuse, and the claim has already ended every one of them.
     *
     * @return array<string, int>
     */
    private function hashedCounts(): array
    {
        $out = [];
        foreach ($this->lines as $line) {
            if (! in_array($line['table'], CredentialRevoker::TABLES, true)) {
                $out[$line['id']] = $line['count'];
            }
        }
        ksort($out);

        return $out;
    }
}
