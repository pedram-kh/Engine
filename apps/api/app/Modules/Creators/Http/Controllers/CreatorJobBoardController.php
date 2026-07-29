<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\CampaignApplicationNotifier;
use App\Modules\Campaigns\Services\JobsBoardVisibility;
use App\Modules\Creators\Http\Requests\ApplyToJobRequest;
use App\Modules\Creators\Http\Resources\CreatorJobCardResource;
use App\Modules\Creators\Http\Resources\CreatorJobDetailResource;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The creator's JOBS BOARD (Jobs Board chunk 3, AH-056).
 *
 *   GET  /api/v1/creators/me/jobs               browse the rostered agencies' listed jobs
 *   GET  /api/v1/creators/me/jobs/{campaign}    one job's detail
 *   POST /api/v1/creators/me/jobs/{campaign}/apply   one-tap apply (+ optional note)
 *
 * Structurally this is the DISCOVER feed (browse a pool you hold no row in:
 * fail-closed whitelist, the same gate re-applied on the detail so a
 * non-qualifying subject is not probeable by ULID, per-caller annotation by
 * correlated subquery, slim projection, paginated envelope, a dedicated narrow
 * resource). It lives in the CREATORS module because the caller is a creator
 * and the surface is `/creators/me/*` — the same split
 * {@see CreatorAssignmentController} already embodies.
 *
 * ⚠ Every read here goes through {@see JobsBoardVisibility::visibleTo()}. The
 * predicate is NOT re-derived per endpoint: list, detail and apply share one
 * builder, because a six-leg tenancy predicate spelled out three times is a
 * predicate with three chances to lose a leg.
 *
 * ⚠ The BelongsToAgency global scope is bypassed deliberately (the documented
 * justified HTTP bypass): the caller is a CREATOR who may be rostered with many
 * agencies, and an ambient tenant context would hide every other agency's jobs.
 * The bypass lives inside the visibility service and in the application
 * annotation below, both explicit.
 */
final class CreatorJobBoardController
{
    private const int DEFAULT_PER_PAGE = 25;

    private const int MAX_PER_PAGE = 100;

    /**
     * The slim card projection. No heavy or leaky campaign columns — no
     * `brief`, no `budget_*` (the creator sees the DISPLAY fee only, never the
     * agency's budget), no internal status, no marketplace flags. `id` and
     * `brand_id` are structural (the eager load + the correlated subqueries
     * need them).
     *
     * @var list<string>
     */
    private const array CARD_COLUMNS = [
        'campaigns.id',
        'campaigns.ulid',
        'campaigns.name',
        'campaigns.brand_id',
        'campaigns.listing_fee',
        'campaigns.listing_duration',
        'campaigns.listed_at',
    ];

    /**
     * What the DETAIL page adds on top of the card. `description` is the
     * CAMPAIGN's job copy (AH-053 D8's relabelled field, AH-054's listing-floor
     * requirement) — never the brand's description, which stays
     * agency-internal.
     *
     * @var list<string>
     */
    private const array DETAIL_COLUMNS = [
        'campaigns.description',
        'campaigns.listing_languages',
        'campaigns.listing_regions',
        'campaigns.listing_examples_url',
    ];

    public function __construct(
        private readonly JobsBoardVisibility $visibility,
    ) {}

    /**
     * GET /api/v1/creators/me/jobs
     *
     * Paginated, newest-listing-first. An unapproved creator, a creator on
     * nobody's roster, and a creator whose agencies have listed nothing all get
     * the same thing: an empty page with a valid envelope. Never an error —
     * "you can see no jobs" is a legitimate state, not a failure.
     */
    public function index(Request $request): JsonResponse
    {
        $creator = $this->requireCreator($request);

        $perPage = (int) $request->integer('per_page', self::DEFAULT_PER_PAGE);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $paginator = $this->visibility->visibleTo($creator)
            ->select(self::CARD_COLUMNS)
            // Applicant count (D4) — every status counts. One subquery, no N+1.
            ->withCount('applications')
            // The CALLER's own application status, so the card can render
            // "Applied" / "Not selected" without a second round trip.
            ->addSelect(['caller_application_status' => $this->callerApplicationSubquery($creator)])
            // The caller's own assignment status (D5), collapsed to a coarse
            // lifecycle state by the resource. On the CARD as well as the detail:
            // D1's fix is branch ordering over this field.
            ->addSelect(['caller_assignment_status' => $this->callerAssignmentStatusSubquery($creator)])
            // Two brand columns only. The card resource cannot emit
            // `website_url` because it is not even loaded here — belt on top of
            // the resource's narrow keyset (D3).
            ->with(['brand:id,name,logo_path'])
            // Newest listing first. `(listed_at IS NULL)` leads the sort so the
            // null placement is identical on Postgres (NULLS FIRST on DESC) and
            // SQLite (NULLS LAST) — an ordering that differs between the test
            // driver and production is a bug waiting for a quiet afternoon.
            ->orderByRaw('(campaigns.listed_at is null) asc')
            ->orderByDesc('campaigns.listed_at')
            ->orderByDesc('campaigns.id')
            ->paginate($perPage)
            ->withQueryString();

        /** @var list<Campaign> $rows */
        $rows = $paginator->items();

        return response()->json([
            'data' => array_map(
                fn (Campaign $campaign): array => (new CreatorJobCardResource($campaign))->resolve($request),
                $rows,
            ),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/creators/me/jobs/{campaign}
     *
     * The SAME predicate, re-applied. A delisted, ended, terminal, un-rostered
     * or brand-hard-blacklisted job is a flat 404 by ULID — never a 403 and
     * never a partial render. That is the discover fail-closed posture: a job
     * the creator may not see must not be PROBEABLE by guessing its identifier,
     * and one indistinguishable code for every reason is the §5.4
     * non-fingerprinting rule.
     */
    public function show(Request $request, string $campaign): JsonResponse
    {
        $creator = $this->requireCreator($request);

        $job = $this->visibility->visibleTo($creator)
            ->select([...self::CARD_COLUMNS, ...self::DETAIL_COLUMNS])
            ->withCount('applications')
            ->addSelect(['caller_application_status' => $this->callerApplicationSubquery($creator)])
            ->addSelect(['caller_assignment_status' => $this->callerAssignmentStatusSubquery($creator)])
            // The D7 bridge — the caller's own assignment on this campaign, so
            // an accepted applicant can reach the offer waiting for them.
            // Detail only (the card has no link to give) — unlike the STATE
            // above, which both surfaces render.
            ->addSelect(['caller_assignment_ulid' => $this->callerAssignmentUlidSubquery($creator)])
            // One extra brand column vs the card — `website_url`, the third and
            // last brand field to cross to a creator audience (D3).
            ->with(['brand:id,name,logo_path,website_url'])
            ->where('campaigns.ulid', $campaign)
            ->first();

        if (! $job instanceof Campaign) {
            abort(404);
        }

        return response()->json([
            'data' => (new CreatorJobDetailResource($job))->resolve($request),
        ]);
    }

    /**
     * POST /api/v1/creators/me/jobs/{campaign}/apply
     *
     * One tap, plus an optional note. Three things happen in order, and the
     * order is the point:
     *
     * 1. **The predicate is RE-VALIDATED server-side.** A board page is a
     *    snapshot; a job can be delisted, ended, taken terminal, or the
     *    creator's roster relation ended or a brand hard-blacklist added,
     *    between the render and the click. Trusting the ULID the client posts
     *    would let any of those write an application the agency can never act
     *    on. A job that has stopped qualifying is a 404, identical to one that
     *    never qualified.
     *
     * 2. **An existing row blocks, with a code that says WHY.** `pending` /
     *    `accepted` → `job.already_applied`; `rejected` →
     *    `job.application_rejected` and Apply stays dead forever (D1's
     *    no-re-apply rule, implemented as the retained terminal row occupying
     *    the unique pair). Two codes rather than one because the SPA renders
     *    them differently, and §5.4's non-fingerprinting rule does not bite:
     *    both facts are the caller's own.
     *
     * 3. **The insert is guarded by the database, not by the check above.**
     *    Two simultaneous taps both pass step 2 and both reach the insert; the
     *    unique index turns the loser into a `QueryException`, which is
     *    translated into the SAME 409 rather than a 500 (§5.6). The check is
     *    the friendly path; the constraint is the correctness.
     */
    public function apply(
        ApplyToJobRequest $request,
        string $campaign,
        CampaignApplicationNotifier $notifier,
    ): JsonResponse {
        $creator = $this->requireCreator($request);

        $job = $this->visibility->findVisible($creator, $campaign);

        if (! $job instanceof Campaign) {
            abort(404);
        }

        $existing = CampaignApplication::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('campaign_id', $job->id)
            ->where('creator_id', $creator->id)
            ->first();

        if ($existing instanceof CampaignApplication) {
            $this->refuseDuplicate($existing->status->reapplyBlockCode());
        }

        try {
            $application = CampaignApplication::query()->create([
                // ⚠ Denormalized from the CAMPAIGN, never from ambient tenancy
                // — the caller is a creator and holds no agency context, so the
                // BelongsToAgency auto-fill would throw rather than guess. This
                // is the single insert site, which is what keeps the
                // denormalization from drifting (Q8).
                'agency_id' => $job->agency_id,
                'campaign_id' => $job->id,
                'creator_id' => $creator->id,
                'status' => CampaignApplicationStatus::Pending,
                'note' => $request->note(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // The §5.6 race: a second tap that got past the read above. Same
            // outcome as the friendly path — the loser is told they already
            // applied, not handed a 500.
            $this->refuseDuplicate('job.already_applied');
        }

        Audit::log(
            action: AuditAction::CampaignApplicationSubmitted,
            subject: $application,
            // The free-text note is deliberately absent — the hand-written-audit
            // discipline. The row records the FACT and the two ids.
            metadata: [
                'campaign_id' => $job->id,
                'creator_id' => $creator->id,
                'has_note' => $application->note !== null,
            ],
            agencyId: $job->agency_id,
        );

        // AH-058 (chunk 4, D6) — the agency hears about it. Chunk 3 wrote this
        // audit verb and deliberately deferred its notification until the agency
        // surface that acts on it existed; it does now (the Applications tab).
        //
        // AFTER the write, never inside a transaction: `after_commit => false`
        // means a queued mail is visible to a worker before any commit. This path
        // has no transaction of its own (one insert, guarded by the unique index),
        // so "after the write" is the same statement here — the ordering is
        // spelled out for the same reason the accept/reject paths spell it out.
        //
        // The campaign is handed over pre-loaded because the notifier's own
        // fallback read is unscoped-by-agency for the queued auto-reject case;
        // this path already holds the visibility-verified row.
        $notifier->submitted($application->setRelation('campaign', $job));

        return response()->json([
            'data' => [
                'id' => $application->ulid,
                'type' => 'campaign_application',
                'attributes' => [
                    'status' => $application->status->value,
                    'note' => $application->note,
                    'created_at' => $application->created_at->toIso8601String(),
                ],
            ],
        ], 201);
    }

    /**
     * @return never
     */
    private function refuseDuplicate(string $code): void
    {
        abort(response()->json([
            'errors' => [[
                'status' => '409',
                'code' => $code,
                'detail' => $code === 'job.application_rejected'
                    ? 'You were not selected for this job, and it cannot be applied to again.'
                    : 'You have already applied to this job.',
            ]],
        ], 409));
    }

    /**
     * Correlated subquery yielding the CALLING creator's application status for
     * each campaign row, or null when they have never applied.
     *
     * Scoped to the caller by an explicit `creator_id` filter, exactly as the
     * discover feed's connection annotation is scoped to the calling agency:
     * this must never surface another creator's application. `limit(1)` is
     * belt-and-suspenders against the (campaign_id, creator_id) uniqueness.
     *
     * BREAK-REVERT: drop the `creator_id` filter and every creator starts
     * reading an arbitrary other creator's application state.
     *
     * @return Builder<CampaignApplication>
     */
    private function callerApplicationSubquery(Creator $creator): Builder
    {
        return CampaignApplication::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->select('status')
            ->whereColumn('campaign_applications.campaign_id', 'campaigns.id')
            ->where('campaign_applications.creator_id', $creator->id)
            ->limit(1);
    }

    /**
     * The D7 bridge (Jobs Board chunk 4, AH-058) — the ULID of the caller's own
     * assignment on this campaign, so an accepted applicant can walk from the
     * job they applied to into the offer that is waiting for them. Detail only:
     * the board card already says "Accepted", and the card's job is to get the
     * creator to the detail page.
     *
     * It is derived from the ASSIGNMENT's existence, never from
     * `application_status === 'accepted'` — the two can disagree. Emitted
     * unconditionally (null when the pair has no assignment) so the detail
     * keyset stays data-independent, and the page renders its accepted notice
     * with or without the link rather than offering a link into nothing.
     *
     * Scoped by `creator_id` exactly as the application subquery above is.
     * BREAK-REVERT: drop that filter and a creator reads another creator's
     * assignment identifier. `limit(1)` is belt on top of the
     * `unique_assignment_campaign_creator` index.
     *
     * @return Builder<CampaignAssignment>
     */
    private function callerAssignmentUlidSubquery(Creator $creator): Builder
    {
        return CampaignAssignment::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->select('ulid')
            ->whereColumn('campaign_assignments.campaign_id', 'campaigns.id')
            ->where('campaign_assignments.creator_id', $creator->id)
            ->limit(1);
    }

    /**
     * The lifecycle reflection's input (AH-059, D5) — the RAW status of the
     * caller's own assignment on this campaign, mapped to the coarse
     * {@see JobLifecycleState} by the resource.
     *
     * Unlike the bridge above, this one is annotated on BOTH the list and the
     * detail. Both surfaces reflect the engagement's stage, and both need it for
     * a second reason: D1's fix is BRANCH ORDERING over this field, so a card
     * without it would keep rendering "Not selected" beside a live invitation —
     * which is the contradiction the whole decision exists to kill. That is why
     * `assignment_state` is on the card while `assignment_ulid` stays detail-only
     * (chunk 4's D7 reasoning is preserved, not reversed): the STATE is what the
     * card renders, the LINK is what the detail offers.
     *
     * The raw status crosses the boundary and is collapsed in the resource rather
     * than mapped in SQL — a CASE expression here would put a second copy of the
     * family mapping in a place PHPStan cannot check for exhaustiveness, which is
     * precisely the failure D5 is built to make impossible.
     *
     * Scoped by `creator_id` exactly as the two subqueries above are.
     * BREAK-REVERT: drop that filter and a creator's job card starts reflecting
     * another creator's engagement.
     *
     * @return Builder<CampaignAssignment>
     */
    private function callerAssignmentStatusSubquery(Creator $creator): Builder
    {
        return CampaignAssignment::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->select('status')
            ->whereColumn('campaign_assignments.campaign_id', 'campaigns.id')
            ->where('campaign_assignments.creator_id', $creator->id)
            ->limit(1);
    }

    private function requireCreator(Request $request): Creator
    {
        /** @var User $user */
        $user = $request->user();
        $creator = $user->creator;

        if (! $creator instanceof Creator) {
            abort(404);
        }

        return $creator;
    }
}
