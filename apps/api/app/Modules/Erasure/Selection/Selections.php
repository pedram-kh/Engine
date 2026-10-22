<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Selection;

use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Agencies\Models\BrandCreatorBlacklist;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorPayoutMethod;
use App\Modules\Creators\Models\CreatorTaxProfile;
use App\Modules\Erasure\Guards\DeliveredWorkGuard;
use App\Modules\Erasure\Guards\PaymentGuard;
use App\Modules\Erasure\Registry\Disposition;
use App\Modules\Erasure\Subject\SubjectSet;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\RelationshipThread;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use stdClass;

/**
 * Every predicate of the erasure, by plan sub-step. Raw query builder only: the
 * Audited trait on User, Creator, CreatorTaxProfile, CreatorPayoutMethod,
 * AgencyCreatorRelation and BrandCreatorBlacklist would write audit rows holding
 * the very data being erased on any Eloquent write, and a global tenant scope
 * would hide rows from other agencies that must be reached.
 *
 * Each selection returns only rows that still need their change, so a second
 * run selects nothing. `ErasureSelectionsCoverageTest` ties this catalog to the
 * registry: a table the registry says to erase, anonymise or scrub with no
 * selection here fails CI, and so does a selection the registry does not call for.
 */
final class Selections
{
    /** What the erasure writes into an encrypted NOT NULL string column. */
    public const ERASED = '[erased]';

    /** The selection the rider's step reads (it is not a raw write, so the engine skips its stage). */
    public const string CANCEL_ASSIGNMENTS = 'campaign_assignments.cancel';

    public const string CANCEL_STAGE = '4h';

    /** @var list<Selection>|null */
    private static ?array $all = null;

    public static function find(string $id): Selection
    {
        foreach (self::all() as $selection) {
            if ($selection->id === $id) {
                return $selection;
            }
        }

        throw new \InvalidArgumentException("No selection {$id}.");
    }

    /** @return list<Selection> */
    public static function all(): array
    {
        return self::$all ??= [
            ...self::identityAndTokens(),
            ...self::satellites(),
            ...self::agencyHeld(),
            ...self::cancellations(),
            ...self::jsonScrubs(),
            ...self::auditScrub(),
        ];
    }

    // ── 4a identity and tokens ──────────────────────────────────────────────

    /** @return list<Selection> */
    private static function identityAndTokens(): array
    {
        $userType = (new User)->getMorphClass();

        return [
            new Selection('users', 'users', Disposition::Anon, '4a', 'the subject user row, until erased_at is set',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('users')->where('id', $s->userId)->whereNull('erased_at')),
            new Selection('creators', 'creators', Disposition::Anon, '4a', 'the subject creator row, until erased_at is set',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('creators')->where('id', $s->creatorId)->whereNull('erased_at')),
            new Selection('sessions', 'sessions', Disposition::EraseRow, '4a', 'sessions of the subject user',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('sessions')->where('user_id', $s->userId)),
            new Selection('personal_access_tokens', 'personal_access_tokens', Disposition::EraseRow, '4a', 'API tokens of the subject user',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('personal_access_tokens')->where('tokenable_type', $userType)->where('tokenable_id', $s->userId)),
            new Selection('password_reset_tokens', 'password_reset_tokens', Disposition::DelMatch, '4a', 'reset tokens keyed by the old email',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('password_reset_tokens')->whereRaw('LOWER(email) = ?', [mb_strtolower($s->oldEmail)])),
            new Selection('user_push_tokens', 'user_push_tokens', Disposition::EraseRow, '4a', 'device tokens of the subject user',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('user_push_tokens')->where('user_id', $s->userId)),
            new Selection('push_deliveries', 'push_deliveries', Disposition::EraseRow, '4a', 'push delivery telemetry of the subject user',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('push_deliveries')->where('user_id', $s->userId)),
            new Selection('user_notification_preferences', 'user_notification_preferences', Disposition::EraseRow, '4a', 'notification preferences of the subject user',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('user_notification_preferences')->where('user_id', $s->userId)),
            new Selection('features', 'features', Disposition::DelMatch, '4a', 'Pennant overrides scoped to the subject user (scope format "{class}|{id}", not verified in production)',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('features')->where('scope', $userType.'|'.$s->userId)),
            new Selection('failed_jobs', 'failed_jobs', Disposition::DelMatch, '4a', 'failed jobs whose payload or exception contains the old email',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => self::textContains($db->table('failed_jobs'), ['payload', 'exception'], $s->oldEmail),
                static fn (stdClass $row, SubjectSet $s): bool => self::mentionsEmail([$row->payload ?? null, $row->exception ?? null], $s->oldEmail)),
        ];
    }

    // ── 4b satellites, pivots and receipts ──────────────────────────────────

    /** @return list<Selection> */
    private static function satellites(): array
    {
        return [
            new Selection('creator_tax_profiles', 'creator_tax_profiles', Disposition::Anon, '4b', 'tax profiles whose legal name, tax id or address is not yet the sentinel',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('creator_tax_profiles')->where('creator_id', $s->creatorId),
                static fn (stdClass $row, SubjectSet $s): bool => ! self::decryptsTo($row->legal_name ?? null, self::ERASED)
                    || ! self::decryptsTo($row->tax_id ?? null, self::ERASED)
                    || ! self::decryptsTo($row->address ?? null, '[]')),
            new Selection('creator_payout_methods', 'creator_payout_methods', Disposition::EraseRow, '4b', 'payout methods of the subject creator',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('creator_payout_methods')->where('creator_id', $s->creatorId)),
            new Selection('creator_kyc_verifications', 'creator_kyc_verifications', Disposition::Anon, '4b', 'KYC rows still holding decision data, a failure reason or a vendor id',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('creator_kyc_verifications')->where('creator_id', $s->creatorId)->where(function (Builder $q): void {
                    $q->whereNotNull('decision_data')->orWhereNotNull('failure_reason')
                        ->orWhereNotNull('provider_session_id')->orWhereNotNull('provider_decision_id');
                })),
            new Selection('creator_social_accounts', 'creator_social_accounts', Disposition::EraseRow, '4b', 'social accounts of the subject creator, trashed included',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('creator_social_accounts')->where('creator_id', $s->creatorId)),
            new Selection('creator_portfolio_items', 'creator_portfolio_items', Disposition::EraseRow, '4b', 'portfolio items of the subject creator, trashed included',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('creator_portfolio_items')->where('creator_id', $s->creatorId)),
            new Selection('creator_availability_blocks', 'creator_availability_blocks', Disposition::EraseRow, '4b', 'availability blocks of the subject creator',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('creator_availability_blocks')->where('creator_id', $s->creatorId)),
            new Selection('talent_pool_creators', 'talent_pool_creators', Disposition::EraseRow, '4b', 'pool memberships of the subject creator',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('talent_pool_creators')->where('creator_id', $s->creatorId)),
            new Selection('campaign_job_notifications', 'campaign_job_notifications', Disposition::EraseRow, '4b', 'job-notified dedupe rows of the subject creator',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('campaign_job_notifications')->where('creator_id', $s->creatorId)),
            new Selection('message_read_receipts', 'message_read_receipts', Disposition::EraseRow, '4b', 'message read receipts of the subject user',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('message_read_receipts')->where('user_id', $s->userId)),
            new Selection('relationship_message_read_receipts', 'relationship_message_read_receipts', Disposition::EraseRow, '4b', 'relationship message read receipts of the subject user',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('relationship_message_read_receipts')->where('user_id', $s->userId)),
            new Selection('message_email_debounces', 'message_email_debounces', Disposition::EraseRow, '4b', 'mail-throttle rows whose recipient is the subject user',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('message_email_debounces')->where('recipient_user_id', $s->userId)),
        ];
    }

    // ── 4c rows an agency holds ─────────────────────────────────────────────

    /** @return list<Selection> */
    private static function agencyHeld(): array
    {
        return [
            new Selection('agency_creator_relations.invitation', 'agency_creator_relations', Disposition::Anon, '4c', 'relations still holding an invitation token hash or expiry',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('agency_creator_relations')->where('creator_id', $s->creatorId)->where(function (Builder $q): void {
                    $q->whereNotNull('invitation_token_hash')->orWhereNotNull('invitation_expires_at');
                })),
            new Selection('campaign_applications.note', 'campaign_applications', Disposition::Anon, '4c', 'applications still holding the creator\'s pitch',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('campaign_applications')->where('creator_id', $s->creatorId)->whereNotNull('note')),
            new Selection('messages.attachments', 'messages', Disposition::ScrubJson, '4c', 'campaign messages sent by the subject user that still carry attachments',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('messages')->where('sender_user_id', $s->userId)->whereNotNull('attachments'),
                static fn (stdClass $row, SubjectSet $s): bool => self::jsonHasContent($row->attachments ?? null)),
            new Selection('relationship_messages.attachments', 'relationship_messages', Disposition::ScrubJson, '4c', 'relationship messages sent by the subject user that still carry attachments',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('relationship_messages')->where('sender_user_id', $s->userId)->whereNotNull('attachments'),
                static fn (stdClass $row, SubjectSet $s): bool => self::jsonHasContent($row->attachments ?? null)),
        ];
    }

    // ── 4h the rider: open assignments are cancelled, not written ───────────

    /**
     * Not a raw write. The state machine does it (audit row, event, notification), so the
     * engine never runs this stage; `Cancellation\CancelOpenAssignments` does, inside the
     * engine's transaction, from the same predicate the dry-run counts.
     *
     * @return list<Selection>
     */
    private static function cancellations(): array
    {
        $terminal = array_values(array_map(
            static fn (AssignmentStatus $status): string => $status->value,
            array_filter(AssignmentStatus::cases(), static fn (AssignmentStatus $status): bool => $status->isTerminal()),
        ));

        // AH-104c: delivered work, and money held or paid, are never cancelled by the rider. The guards
        // refuse the run first, so this is belt and braces: a defect in one place cannot cancel work
        // that may be payable.
        $excluded = array_values(array_unique([...$terminal, ...DeliveredWorkGuard::states(), ...PaymentGuard::knownPaymentStates()]));

        return [
            new Selection(self::CANCEL_ASSIGNMENTS, 'campaign_assignments', Disposition::Cancel, self::CANCEL_STAGE, 'the subject creator\'s assignments that are not yet in a terminal state and carry no delivered work',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('campaign_assignments')->where('creator_id', $s->creatorId)->whereNull('deleted_at')->whereNotIn('status', $excluded)),
        ];
    }

    // ── 4d JSON scrubs that need no role ────────────────────────────────────

    /** @return list<Selection> */
    private static function jsonScrubs(): array
    {
        return [
            new Selection('notifications.own_feed', 'notifications', Disposition::EraseRow, '4d', 'the subject user\'s own notification feed',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('notifications')->where('recipient_user_id', $s->userId)),
            new Selection('notifications.actor', 'notifications', Disposition::ScrubJson, '4d', 'rows on other feeds the subject acted on, still carrying sender_name',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('notifications')->where('actor_user_id', $s->userId)->where('recipient_user_id', '!=', $s->userId),
                static fn (stdClass $row, SubjectSet $s): bool => self::jsonHasAnyKey($row->data ?? null, ['sender_name'])),
            new Selection('notifications.subject', 'notifications', Disposition::ScrubJson, '4d', 'rows on other feeds whose subject is the creator\'s own record, still carrying creator_name or counterparty_name',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => self::notificationsAboutSubject($s, $db),
                static fn (stdClass $row, SubjectSet $s): bool => self::jsonHasAnyKey($row->data ?? null, ['creator_name', 'counterparty_name'])),
            new Selection('tracked_jobs.failures', 'tracked_jobs', Disposition::ScrubJson, '4d', 'bulk-invite jobs whose failures list the old email',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => self::textContains($db->table('tracked_jobs'), ['result'], $s->oldEmail),
                static fn (stdClass $row, SubjectSet $s): bool => self::failuresNameEmail($row->result ?? null, $s->oldEmail)),
            new Selection('integration_events.payload', 'integration_events', Disposition::ScrubJson, '4d', 'vendor events whose payload contains a subject vendor id and is not yet erased',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => self::vendorEvents($s, $db)),
        ];
    }

    // ── 4e the audit scrub ──────────────────────────────────────────────────

    /**
     * Each predicate returns the rows it covers and counts only those the scrub
     * would still change ({@see AuditRowScrub}); the scrub is `DatabaseAuditScrub`.
     *
     * @return list<Selection>
     */
    private static function auditScrub(): array
    {
        $changes = static fn (string $p): Closure => static fn (stdClass $row, SubjectSet $s): bool => AuditRowScrub::changes($row, $s, $p) !== [];

        return [
            new Selection('audit_logs.S1', 'audit_logs', Disposition::ScrubJson, '4e', 'S1 the subject user as actor',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => self::auditS1($s, $db), $changes('S1')),
            new Selection('audit_logs.S2', 'audit_logs', Disposition::ScrubJson, '4e', 'S2 the subject user as subject',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => self::auditSubject($db->table('audit_logs'), (new User)->getMorphClass(), [$s->userId]), $changes('S2')),
            new Selection('audit_logs.S3', 'audit_logs', Disposition::ScrubJson, '4e', 'S3 the subject creator as subject',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => self::auditSubject($db->table('audit_logs'), (new Creator)->getMorphClass(), [$s->creatorId]), $changes('S3')),
            new Selection('audit_logs.S4', 'audit_logs', Disposition::ScrubJson, '4e', 'S4 the creator\'s tax profiles, payout methods, relations and blacklists as subject',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => self::auditS4($s, $db), $changes('S4')),
            new Selection('audit_logs.S5', 'audit_logs', Disposition::ScrubJson, '4e', 'S5 any actor, metadata.email equal to the old email',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => self::auditS5($s, $db), $changes('S5')),
            new Selection('audit_logs.union', 'audit_logs', Disposition::ScrubJson, '4e', 'the distinct audit rows (S1 to S5) the scrub will change',
                fn (SubjectSet $s, ConnectionInterface $db): Builder => $db->table('audit_logs')->where(function (Builder $q) use ($s, $db): void {
                    $q->whereIn('id', self::auditS1($s, $db)->select('id'))
                        ->orWhereIn('id', self::auditSubject($db->table('audit_logs'), (new User)->getMorphClass(), [$s->userId])->select('id'))
                        ->orWhereIn('id', self::auditSubject($db->table('audit_logs'), (new Creator)->getMorphClass(), [$s->creatorId])->select('id'))
                        ->orWhereIn('id', self::auditS4($s, $db)->select('id'))
                        ->orWhereIn('id', self::auditS5($s, $db)->select('id'));
                }),
                static fn (stdClass $row, SubjectSet $s): bool => AuditRowScrub::changes($row, $s) !== []),
        ];
    }

    // ── query pieces ────────────────────────────────────────────────────────

    private static function auditS1(SubjectSet $s, ConnectionInterface $db): Builder
    {
        return $db->table('audit_logs')->where('actor_type', 'user')->where('actor_id', $s->userId);
    }

    /** @param list<int> $ids */
    private static function auditSubject(Builder $q, string $type, array $ids): Builder
    {
        return $q->where('subject_type', $type)->whereIn('subject_id', $ids);
    }

    private static function auditS4(SubjectSet $s, ConnectionInterface $db): Builder
    {
        $kinds = [
            (new CreatorTaxProfile)->getMorphClass() => $s->taxProfileIds,
            (new CreatorPayoutMethod)->getMorphClass() => $s->payoutMethodIds,
            (new AgencyCreatorRelation)->getMorphClass() => $s->relationIds,
            (new BrandCreatorBlacklist)->getMorphClass() => $s->blacklistIds,
        ];

        return $db->table('audit_logs')->where(function (Builder $q) use ($kinds): void {
            foreach ($kinds as $type => $ids) {
                $q->orWhere(fn (Builder $w): Builder => $w->where('subject_type', $type)->whereIn('subject_id', $ids));
            }
        });
    }

    private static function auditS5(SubjectSet $s, ConnectionInterface $db): Builder
    {
        // Case-insensitive: a failed login records the address as typed. No index serves a JSON key
        // (audit_logs is read once, in full, per erasure; 38 MB at the production read-back).
        $query = $db->table('audit_logs');

        return $query->whereRaw('LOWER('.$query->getGrammar()->wrap('metadata->email').') = ?', [mb_strtolower($s->oldEmail)]);
    }

    /** Notifications on OTHER feeds whose subject is the creator or one of the creator's own records. */
    private static function notificationsAboutSubject(SubjectSet $s, ConnectionInterface $db): Builder
    {
        $kinds = [
            (new Creator)->getMorphClass() => [$s->creatorId],
            (new CampaignAssignment)->getMorphClass() => $s->assignmentIds,
            (new CampaignApplication)->getMorphClass() => $s->applicationIds,
            (new MessageThread)->getMorphClass() => $s->messageThreadIds,
            (new RelationshipThread)->getMorphClass() => $s->relationshipThreadIds,
            (new AgencyCreatorRelation)->getMorphClass() => $s->relationIds,
        ];

        return $db->table('notifications')
            ->where('recipient_user_id', '!=', $s->userId)
            ->where(function (Builder $q) use ($kinds): void {
                foreach ($kinds as $type => $ids) {
                    $q->orWhere(fn (Builder $w): Builder => $w->where('subject_type', $type)->whereIn('subject_id', $ids));
                }
            });
    }

    private static function vendorEvents(SubjectSet $s, ConnectionInterface $db): Builder
    {
        $q = $db->table('integration_events')->whereRaw("CAST(payload AS TEXT) NOT LIKE '%\"erased\"%'");
        if ($s->vendorIds === []) {
            return $q->whereRaw('1 = 0');
        }

        return $q->where(function (Builder $w) use ($s): void {
            foreach ($s->vendorIds as $id) {
                $w->orWhereRaw("CAST(payload AS TEXT) LIKE ? ESCAPE '!'", ['%'.self::escapeLike($id).'%']);
            }
        });
    }

    /**
     * Case-insensitive "column contains needle" over several text columns, with
     * LIKE wildcards in the needle escaped (an address may contain `_`).
     *
     * @param  list<string>  $columns
     */
    private static function textContains(Builder $q, array $columns, string $needle): Builder
    {
        $pattern = '%'.self::escapeLike(mb_strtolower($needle)).'%';

        return $q->where(function (Builder $w) use ($columns, $pattern): void {
            foreach ($columns as $column) {
                $w->orWhereRaw("LOWER(CAST({$column} AS TEXT)) LIKE ? ESCAPE '!'", [$pattern]);
            }
        });
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    // ── per-row tests (PHP side, for what SQL cannot see) ───────────────────

    private static function decryptsTo(mixed $cipher, string $expected): bool
    {
        if (! is_string($cipher) || $cipher === '') {
            return false;
        }
        try {
            return Crypt::decryptString($cipher) === $expected;
        } catch (DecryptException) {
            return false;
        }
    }

    private static function jsonHasContent(mixed $raw): bool
    {
        $decoded = self::decode($raw);

        return is_array($decoded) && $decoded !== [];
    }

    /** @param list<string> $keys */
    private static function jsonHasAnyKey(mixed $raw, array $keys): bool
    {
        $decoded = self::decode($raw);
        if (! is_array($decoded)) {
            return false;
        }
        foreach ($keys as $key) {
            if (array_key_exists($key, $decoded) && $decoded[$key] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whole-address match: `subject@example.test` must not select a failed job
     * that belongs to `notsubject@example.test`. The SQL LIKE is only the
     * candidate filter; this decides.
     *
     * @param  list<mixed>  $texts
     */
    private static function mentionsEmail(array $texts, string $email): bool
    {
        $pattern = '/(?<![A-Za-z0-9._%+\-])'.preg_quote($email, '/').'(?![A-Za-z0-9_\-]|\.[A-Za-z0-9])/i';
        foreach ($texts as $text) {
            if (is_string($text) && preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function failuresNameEmail(mixed $raw, string $email): bool
    {
        $decoded = self::decode($raw);
        $failures = is_array($decoded) ? ($decoded['failures'] ?? null) : null;
        if (! is_array($failures)) {
            return false;
        }
        foreach ($failures as $failure) {
            if (is_array($failure) && is_string($failure['email'] ?? null) && mb_strtolower($failure['email']) === mb_strtolower($email)) {
                return true;
            }
        }

        return false;
    }

    private static function decode(mixed $raw): mixed
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return json_decode($raw, true);
    }
}
