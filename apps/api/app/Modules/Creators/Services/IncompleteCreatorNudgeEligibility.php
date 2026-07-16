<?php

declare(strict_types=1);

namespace App\Modules\Creators\Services;

use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Models\Creator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the two eligible populations for the incomplete-creator email nudge
 * (docs/reviews/incomplete-creator-nudge-review.md, D2/D3).
 *
 * The shared predicate — every eligible creator:
 *
 *   - `application_status = incomplete`               (D2; the admin queue's
 *                                                      Incomplete filter — a
 *                                                      single equality, exactly
 *                                                      AdminCreatorController).
 *   - `created_at <= now() - 48h`                     (D3; anchor = Creator.created_at,
 *                                                      lossiness accepted. A row
 *                                                      reopened rejected→incomplete
 *                                                      becomes eligible on reopen
 *                                                      if never nudged — acceptable:
 *                                                      it is genuinely old + incomplete,
 *                                                      and the once-only stamp caps it).
 *   - `incomplete_nudge_sent_at IS NULL`              (once-only; the send-stamp).
 *   - the User is NOT suspended                       (plan-pause extension of D2:
 *                                                      intent was "nudge only people
 *                                                      who can act on it" — a suspended
 *                                                      user hits the login wall).
 *   - the User is NOT soft-deleted                    (whereHas('user') applies the
 *                                                      User SoftDeletes global scope).
 *   - SELF-SERVE ORIGIN ONLY                          (D2/Q1): the creator has NO
 *                                                      agency_creator_relations row
 *                                                      bearing an invitation
 *                                                      (`invitation_sent_at IS NOT NULL`).
 *                                                      That row is the durable marker of
 *                                                      an agency invite/connection path,
 *                                                      whose correct next step could be
 *                                                      accept-invite — so nobody there
 *                                                      receives a verify-email link.
 *
 * The two variants then split on the User's `email_verified_at`:
 *
 *   - verify variant   (`email_verified_at IS NULL`)     → a fresh verify-email link.
 *   - finish variant   (`email_verified_at IS NOT NULL`) → the finish-profile deep link.
 *
 * D7: no supporting index this chunk — a daily batch with status-indexed
 * narrowing at current scale (docs/tech-debt.md, volume-triggered).
 */
final class IncompleteCreatorNudgeEligibility
{
    /**
     * The "sitting incomplete" floor, in hours. A class constant (not env):
     * 48h is a product invariant, mirroring EmailVerificationToken::LIFETIME_HOURS.
     */
    public const int THRESHOLD_HOURS = 48;

    /**
     * The oldest-first, capped combined run set (production-safety addendum).
     * A single ordered+limited query is the deterministic per-run cap: the
     * `$limit` oldest eligible creators (by `created_at`, `id` tie-break) drain
     * the backlog in a stable order, so a capped run never re-orders or skips
     * ahead. The service partitions this set by `email_verified_at` into the
     * two variants — so the cap is a TRUE per-run total, not per-variant.
     *
     * @return Collection<int, Creator>
     */
    public function eligible(int $limit): Collection
    {
        return $this->commonQuery()
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Unverified self-serve creators (the verify-email variant). Unbounded —
     * used by the §5.34 predicate tests; the capped run uses {@see self::eligible()}.
     *
     * @return Collection<int, Creator>
     */
    public function verifyVariant(): Collection
    {
        return $this->commonQuery()
            ->whereHas('user', static fn (Builder $q): Builder => $q->whereNull('email_verified_at'))
            ->get();
    }

    /**
     * Verified self-serve creators still mid-onboarding (the finish-profile variant).
     *
     * @return Collection<int, Creator>
     */
    public function finishVariant(): Collection
    {
        return $this->commonQuery()
            ->whereHas('user', static fn (Builder $q): Builder => $q->whereNotNull('email_verified_at'))
            ->get();
    }

    /**
     * The single source of the shared eligibility predicate (D2/D3/Q1) — every
     * eligible creator, WITHOUT the variant `email_verified_at` split. Both the
     * capped run ({@see self::eligible()}) and the unbounded variant queries
     * layer on top of this, so the predicate lives in exactly one place.
     *
     * @return Builder<Creator>
     */
    private function commonQuery(): Builder
    {
        return Creator::query()
            ->where('application_status', ApplicationStatus::Incomplete->value)
            ->whereNull('incomplete_nudge_sent_at')
            ->where('created_at', '<=', now()->subHours(self::THRESHOLD_HOURS))
            ->whereHas('user', static fn (Builder $q): Builder => $q->where('is_suspended', false))
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw('1'))
                    ->from('agency_creator_relations')
                    ->whereColumn('agency_creator_relations.creator_id', 'creators.id')
                    ->whereNotNull('agency_creator_relations.invitation_sent_at');
            })
            ->with('user');
    }
}
