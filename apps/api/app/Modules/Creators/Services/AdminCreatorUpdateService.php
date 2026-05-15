<?php

declare(strict_types=1);

namespace App\Modules\Creators\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Per-field admin edit service for Creator rows (Sprint 3 Chunk 4).
 *
 * Each call updates exactly one field, emitting a single
 * {@see AuditAction::CreatorAdminFieldUpdated} audit row inside a
 * transaction.
 *
 * Idempotency (#6): same-value updates are no-ops. The service
 * pre-compares the incoming value to the current value and skips the
 * save() + audit when they're equal — `updated_at` is NOT touched and
 * no audit row is emitted. Re-posting the same edit ten times produces
 * exactly one audit row (the first one) and zero updates.
 *
 * Transactional audit (#5): the audit row + the column write live in the
 * same DB transaction. A failure mid-write rolls both back.
 *
 * Out of scope: application_status edits. Those go through the dedicated
 * approve / reject controller methods (separation of concerns per
 * Decision E2=b + Q-chunk-4-2 = (a)).
 */
final class AdminCreatorUpdateService
{
    /**
     * @return array{updated: bool, before?: mixed, after?: mixed}
     */
    public function updateField(
        Creator $creator,
        User $admin,
        string $field,
        mixed $value,
        ?string $reason,
    ): array {
        return DB::transaction(function () use ($creator, $admin, $field, $value, $reason): array {
            $before = $creator->getAttribute($field);

            // Normalise array-shaped fields for deep equality. Eloquent
            // returns categories / secondary_languages as PHP arrays after
            // jsonb casting; we compare arrays via order-insensitive set
            // equality so [a, b] === [b, a] for the idempotency check
            // (multi-select UI doesn't preserve order).
            if (is_array($before) && is_array($value)) {
                $beforeNormalised = $this->canonicalise($before);
                $valueNormalised = $this->canonicalise($value);
                if ($beforeNormalised === $valueNormalised) {
                    return ['updated' => false];
                }
            } elseif ($before === $value) {
                return ['updated' => false];
            }

            $creator->setAttribute($field, $value);
            $creator->save();

            Audit::log(
                action: AuditAction::CreatorAdminFieldUpdated,
                actor: $admin,
                subject: $creator,
                metadata: array_filter([
                    'field' => $field,
                    'before' => $before,
                    'after' => $value,
                    'reason' => $reason,
                ], static fn ($v): bool => $v !== null),
            );

            return [
                'updated' => true,
                'before' => $before,
                'after' => $value,
            ];
        });
    }

    /**
     * Canonical form for array equality: lowercased + sorted. Strings
     * (locale codes, category slugs) only — the array fields admin can
     * edit are all string-valued.
     *
     * @param  array<int|string, mixed>  $arr
     * @return list<string>
     */
    private function canonicalise(array $arr): array
    {
        $values = array_map(
            static fn ($v): string => is_string($v) ? mb_strtolower($v) : (string) $v,
            array_values($arr),
        );
        sort($values);

        return $values;
    }
}
