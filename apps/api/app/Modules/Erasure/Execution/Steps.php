<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Execution;

use App\Modules\Erasure\Registry\Disposition;
use App\Modules\Erasure\Registry\ErasureRegistry;
use App\Modules\Erasure\Selection\Selection;
use App\Modules\Erasure\Subject\SubjectSet;
use Closure;
use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * The write for each selection of stages 4a to 4d, keyed by selection id.
 *
 * Every writer changes exactly the rows its selection returns (the selection is
 * the only place a predicate is written), through the raw query builder, so no
 * model event, no Audited trait and no tenant scope is involved. A writer
 * returns the number of rows it changed, which a spec holds equal to the
 * dry-run's count for the same selection.
 *
 * The audit scrub (4e), the files (4f) and the vendors (4g) are not here: they
 * need a second connection, a disk and provider contracts, and refuse to run
 * without them.
 */
final class Steps
{
    private const CHUNK = 500;

    /** @return array<string, Closure(Selection, SubjectSet, ConnectionInterface, string): int> */
    public static function writers(): array
    {
        $delete = fn (Selection $sel, SubjectSet $s, ConnectionInterface $db, string $now): int => self::deleteRows($sel, $s, $db);
        $anon = fn (Selection $sel, SubjectSet $s, ConnectionInterface $db, string $now): int => self::anonymise($sel, $s, $db, $now);

        return [
            // 4a identity and tokens
            'users' => $anon,
            'creators' => $anon,
            'sessions' => $delete,
            'personal_access_tokens' => $delete,
            'password_reset_tokens' => $delete,
            'user_push_tokens' => $delete,
            'push_deliveries' => $delete,
            'user_notification_preferences' => $delete,
            'features' => $delete,
            'failed_jobs' => $delete,
            // 4b satellites
            'creator_tax_profiles' => $anon,
            'creator_payout_methods' => $delete,
            'creator_kyc_verifications' => $anon,
            'creator_social_accounts' => $delete,
            'creator_portfolio_items' => $delete,
            'creator_availability_blocks' => $delete,
            'talent_pool_creators' => $delete,
            'campaign_job_notifications' => $delete,
            'message_read_receipts' => $delete,
            'relationship_message_read_receipts' => $delete,
            'message_email_debounces' => $delete,
            // 4c rows an agency holds
            'agency_creator_relations.invitation' => $anon,
            'campaign_applications.note' => $anon,
            'messages.attachments' => fn (Selection $sel, SubjectSet $s, ConnectionInterface $db, string $now): int => self::updateEach($sel, $s, $db, fn (stdClass $row): array => ['attachments' => null]),
            'relationship_messages.attachments' => fn (Selection $sel, SubjectSet $s, ConnectionInterface $db, string $now): int => self::updateEach($sel, $s, $db, fn (stdClass $row): array => ['attachments' => null]),
            // 4d JSON scrubs
            'notifications.own_feed' => $delete,
            'notifications.actor' => fn (Selection $sel, SubjectSet $s, ConnectionInterface $db, string $now): int => self::updateEach($sel, $s, $db, fn (stdClass $row): array => ['data' => self::withoutKeys($row->data ?? null, ['sender_name'])]),
            'notifications.subject' => fn (Selection $sel, SubjectSet $s, ConnectionInterface $db, string $now): int => self::updateEach($sel, $s, $db, fn (stdClass $row): array => ['data' => self::withoutKeys($row->data ?? null, ['creator_name', 'counterparty_name'])]),
            'tracked_jobs.failures' => fn (Selection $sel, SubjectSet $s, ConnectionInterface $db, string $now): int => self::updateEach($sel, $s, $db, fn (stdClass $row): array => ['result' => self::withFailureEmailNulled($row->result ?? null, $s->oldEmail)]),
            'integration_events.payload' => fn (Selection $sel, SubjectSet $s, ConnectionInterface $db, string $now): int => self::updateEach($sel, $s, $db, fn (stdClass $row): array => ['payload' => '{"erased":true}']),
        ];
    }

    // ── generic writers ─────────────────────────────────────────────────────

    private static function deleteRows(Selection $sel, SubjectSet $s, ConnectionInterface $db): int
    {
        if (! $sel->isFiltered()) {
            return $sel->builder($s, $db)->delete();
        }

        $deleted = 0;
        foreach (array_chunk(self::matchingIds($sel, $s, $db), self::CHUNK) as $ids) {
            $deleted += $db->table($sel->table)->whereIn('id', $ids)->delete();
        }

        return $deleted;
    }

    /**
     * Overwrite the registry's ANON columns of the selected rows. Where the
     * selection is filtered per row (encrypted columns) each row gets its own
     * fresh values; otherwise one statement covers the selection.
     */
    private static function anonymise(Selection $sel, SubjectSet $s, ConnectionInterface $db, string $now): int
    {
        $columns = [];
        foreach (ErasureRegistry::tables()[$sel->table]->columns as $name => $rule) {
            if ($rule->disposition === Disposition::Anon && $rule->anon !== null) {
                $columns[$name] = $rule->anon;
            }
        }

        $assign = static function () use ($columns, $s, $now): array {
            $values = [];
            foreach ($columns as $name => $anon) {
                $values[$name] = AnonResolver::resolve($anon, $s, $now);
            }

            return $values;
        };

        if (! $sel->isFiltered()) {
            return $sel->builder($s, $db)->update($assign());
        }

        $changed = 0;
        foreach (self::matchingIds($sel, $s, $db) as $id) {
            $changed += $db->table($sel->table)->where('id', $id)->update($assign());
        }

        return $changed;
    }

    /**
     * Update each selected row by id with the columns the callback returns.
     *
     * @param  Closure(stdClass): array<string, mixed>  $changes
     */
    private static function updateEach(Selection $sel, SubjectSet $s, ConnectionInterface $db, Closure $changes): int
    {
        $changed = 0;
        // get(), not cursor(): an open cursor on a table that is also being updated
        // can lock on some drivers. The candidates are narrow (the selection already filtered).
        foreach ($sel->builder($s, $db)->get() as $row) {
            if (! $row instanceof stdClass || ! $sel->rowNeedsChange($row, $s)) {
                continue;
            }
            $changed += $db->table($sel->table)->where('id', $row->id)->update($changes($row));
        }

        return $changed;
    }

    /** @return list<int> */
    private static function matchingIds(Selection $sel, SubjectSet $s, ConnectionInterface $db): array
    {
        $ids = [];
        foreach ($sel->builder($s, $db)->get() as $row) {
            if ($row instanceof stdClass && $sel->rowNeedsChange($row, $s) && is_numeric($row->id)) {
                $ids[] = (int) $row->id;
            }
        }

        return $ids;
    }

    // ── JSON edits ──────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $keys
     */
    private static function withoutKeys(mixed $raw, array $keys): string
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
        $decoded = is_array($decoded) ? $decoded : [];
        foreach ($keys as $key) {
            unset($decoded[$key]);
        }

        return json_encode($decoded, JSON_THROW_ON_ERROR);
    }

    /**
     * The failure rows keep their row number and reason (useful to the agency);
     * only the typed address of the erased person goes.
     */
    private static function withFailureEmailNulled(mixed $raw, string $email): string
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
        $decoded = is_array($decoded) ? $decoded : [];
        $failures = $decoded['failures'] ?? null;
        if (is_array($failures)) {
            foreach ($failures as $i => $failure) {
                if (is_array($failure) && is_string($failure['email'] ?? null) && mb_strtolower($failure['email']) === mb_strtolower($email)) {
                    $failures[$i]['email'] = null;
                }
            }
            $decoded['failures'] = $failures;
        }

        return json_encode($decoded, JSON_THROW_ON_ERROR);
    }
}
