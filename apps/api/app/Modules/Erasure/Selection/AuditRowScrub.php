<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Selection;

use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Agencies\Models\BrandCreatorBlacklist;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorPayoutMethod;
use App\Modules\Creators\Models\CreatorTaxProfile;
use App\Modules\Erasure\Subject\SubjectSet;
use App\Modules\Identity\Models\User;
use stdClass;

/**
 * What the audit scrub (stage 4e, PII map §4.1) changes in ONE audit row.
 *
 * A pure function of the row and the subject: which of S1 to S5 the row is in,
 * and the new value of each column that would change. The dry-run counts rows
 * with it and the scrub writes with it, so the two cannot disagree, and a row
 * that is already clean produces no change (a second run changes nothing).
 *
 * Only the five columns in {@see self::COLUMNS} are ever produced; the writer
 * refuses anything else (the in-code guard that replaces a separate database
 * role: the application role can UPDATE the whole table, this code must not).
 *
 * Kept on purpose (D-D and the map): `reason`, `metadata.rejection_reason`,
 * `metadata.note`, every id and ulid, `impersonator_user_id`, `actor_role`.
 */
final class AuditRowScrub
{
    /** The only columns the scrub may write. */
    public const array COLUMNS = ['ip', 'user_agent', 'metadata', 'before', 'after'];

    public const array PREDICATES = ['S1', 'S2', 'S3', 'S4', 'S5'];

    /** Keys of a user snapshot (`before` / `after`) that are the person. */
    private const array USER_SNAPSHOT_KEYS = ['email', 'name', 'last_name', 'last_login_ip', 'suspended_reason'];

    /** @return array<string, bool> predicate => whether the row is in it */
    public static function membership(stdClass $row, SubjectSet $s): array
    {
        $actorIsSubject = $row->actor_type === 'user' && (int) $row->actor_id === $s->userId;
        $subjectId = $row->subject_id === null ? null : (int) $row->subject_id;

        $satellites = [
            (new CreatorTaxProfile)->getMorphClass() => $s->taxProfileIds,
            (new CreatorPayoutMethod)->getMorphClass() => $s->payoutMethodIds,
            (new AgencyCreatorRelation)->getMorphClass() => $s->relationIds,
            (new BrandCreatorBlacklist)->getMorphClass() => $s->blacklistIds,
        ];

        return [
            'S1' => $actorIsSubject,
            'S2' => $row->subject_type === (new User)->getMorphClass() && $subjectId === $s->userId,
            'S3' => $row->subject_type === (new Creator)->getMorphClass() && $subjectId === $s->creatorId,
            'S4' => is_string($row->subject_type) && $subjectId !== null && in_array($subjectId, $satellites[$row->subject_type] ?? [], true),
            'S5' => self::metadataEmail($row) === mb_strtolower($s->oldEmail),
        ];
    }

    /**
     * The columns that would change, with their new values; empty when nothing would.
     *
     * @param  string|null  $only  Restrict to one predicate (the per-predicate counts of the dry-run).
     * @return array<string, string|null>
     */
    public static function changes(stdClass $row, SubjectSet $s, ?string $only = null): array
    {
        $member = self::membership($row, $s);
        $dropIp = false;
        $drop = ['metadata' => [], 'before' => [], 'after' => []];

        foreach (self::PREDICATES as $p) {
            if (! $member[$p] || ($only !== null && $only !== $p)) {
                continue;
            }
            $r = self::removals($p, $row, $s);
            $dropIp = $dropIp || $r['ip'];
            foreach (['metadata', 'before', 'after'] as $column) {
                $drop[$column] = [...$drop[$column], ...$r[$column]];
            }
        }

        $out = [];
        if ($dropIp) {
            if ($row->ip !== null) {
                $out['ip'] = null;
            }
            if ($row->user_agent !== null) {
                $out['user_agent'] = null;
            }
        }
        foreach (['metadata', 'before', 'after'] as $column) {
            if ($drop[$column] === []) {
                continue;
            }
            $without = self::withoutKeys($row->{$column} ?? null, array_values(array_unique($drop[$column])));
            if ($without !== null) {
                $out[$column] = $without;
            }
        }

        return $out;
    }

    /** @return array{ip: bool, metadata: list<string>, before: list<string>, after: list<string>} */
    private static function removals(string $predicate, stdClass $row, SubjectSet $s): array
    {
        $none = ['ip' => false, 'metadata' => [], 'before' => [], 'after' => []];

        return match ($predicate) {
            // The creator's own actions: where they were (ip, device) and the address a login or reset typed.
            'S1' => ['ip' => true, 'metadata' => ['email']] + $none,
            // What was done TO the user: the snapshots hold the person's own columns.
            'S2' => ['before' => self::USER_SNAPSHOT_KEYS, 'after' => self::USER_SNAPSHOT_KEYS] + $none,
            'S3' => [
                'before' => ['display_name'],
                'after' => ['display_name'],
                // The old and new value of whichever field an admin edited; the field's NAME and the reason stay.
                'metadata' => match ($row->action) {
                    AuditAction::CreatorAdminFieldUpdated->value => ['before', 'after'],
                    AuditAction::CreatorApproved->value => ['welcome_message'],
                    default => [],
                },
            ] + $none,
            'S4' => ['before' => ['provider_account_id'], 'after' => ['provider_account_id']] + $none,
            // Any actor, including none (a failed login carries the typed address). The ip of a row
            // ANOTHER user acted in is that user's, not the subject's, and stays.
            'S5' => ['ip' => ! ($row->actor_type === 'user' && (int) $row->actor_id !== $s->userId), 'metadata' => ['email']] + $none,
            default => $none,
        };
    }

    private static function metadataEmail(stdClass $row): ?string
    {
        $metadata = self::decode($row->metadata ?? null);
        $email = $metadata['email'] ?? null;

        return is_string($email) ? mb_strtolower($email) : null;
    }

    /** @return array<string|int, mixed> */
    private static function decode(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The JSON with those top-level keys removed, or null when none was present (nothing to change).
     *
     * @param  list<string>  $keys
     */
    private static function withoutKeys(mixed $raw, array $keys): ?string
    {
        $decoded = self::decode($raw);
        $present = false;
        foreach ($keys as $key) {
            if (array_key_exists($key, $decoded)) {
                unset($decoded[$key]);
                $present = true;
            }
        }
        if (! $present) {
            return null;
        }

        // An emptied object stays an object ({}), not a list ([]).
        return json_encode($decoded === [] ? (object) [] : $decoded, JSON_THROW_ON_ERROR);
    }
}
