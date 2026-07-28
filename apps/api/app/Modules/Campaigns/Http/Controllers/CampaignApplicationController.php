<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Campaigns\Enums\ApplicationRejectionCause;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Campaigns\Http\Requests\AcceptApplicationRequest;
use App\Modules\Campaigns\Http\Resources\CampaignApplicationListItemResource;
use App\Modules\Campaigns\Http\Resources\CampaignAssignmentResource;
use App\Modules\Campaigns\Jobs\AutoRejectPendingApplicationsJob;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\AssignmentInviteGate;
use App\Modules\Campaigns\Services\AssignmentOfferAttachmentUploadService;
use App\Modules\Campaigns\Services\CampaignApplicationNotifier;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use App\Modules\Campaigns\Services\CampaignInvitationService;
use App\Modules\Campaigns\ValueObjects\AssignmentOffer;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Agency-side JOB APPLICATIONS — the campaign-detail Applications tab
 * (Jobs Board chunk 4, AH-058).
 *
 *   index  — the tab's list, `view`-gated (any member reads).
 *   accept — the offer form that turns an application into a real invitation.
 *   reject — the agency's polite no.
 *
 * ── Why a TAB and not a board column (D1, a recorded §5.32 reinterpretation) ─
 *
 * Chunk 3's migration docblock called this an "agency-side board column". The
 * board cannot host it: `board_cards.campaign_assignment_id` is NOT NULL, UNIQUE
 * and CASCADE — a card IS an assignment at three layers — and an application has
 * no assignment yet, by definition. The board's own §4.4 invariant is that a drag
 * is consequence-free, which cannot express accept or reject. Nothing shipped is
 * wasted: the `(agency_id, status)` index chunk 3 added for this serves the
 * status-scoped list here identically.
 *
 * The board then handles post-accept for FREE — `CreateBoardCard` on
 * `assignment.invited` plus the `assignment.invited → Invited` automation — which
 * the accept spec asserts rather than rebuilds.
 *
 * ── Abilities (Q4, recorded) ────────────────────────────────────────────────
 *
 * `view` for the list; `invite` for BOTH accept and reject. A fifth
 * admin+manager+staff clone of the same three roles (`review`, `message`,
 * `attachContract` are already that) would buy a better name and nothing else,
 * and the tab's two actions sitting under one execute ability is honest: whoever
 * may put an offer in front of a creator is whoever may answer their application.
 */
final class CampaignApplicationController
{
    /**
     * GET /api/v1/agencies/{agency}/campaigns/{campaign}/applications
     *
     * PENDING FIRST, then newest (D1). The ordering is the tab's whole
     * information design: the rows that need a decision are the rows an agency
     * opened the tab for, and an answered application is history.
     */
    public function index(Request $request, Agency $agency, Campaign $campaign): JsonResponse
    {
        $this->assertBelongsToAgency($campaign, $agency);
        Gate::authorize('view', $campaign);

        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        $query = CampaignApplication::query()
            ->where('campaign_applications.campaign_id', $campaign->id)
            // Belt-and-suspenders alongside the tenancy global scope, matching
            // the assignments/drafts endpoints: the campaign is already proven to
            // belong to this agency, and the row carries its own denormalized
            // agency_id, so both are asserted rather than one trusted.
            ->where('campaign_applications.agency_id', $agency->id);

        $this->applyStatusFilter($query, $request);

        $paginator = $query
            ->with(['creator:id,ulid,display_name,avatar_path'])
            // `pending` sorts first via an explicit CASE rather than by relying
            // on the enum's alphabetical accident (accepted < pending < rejected
            // would put answered rows on top).
            ->orderByRaw('CASE WHEN campaign_applications.status = ? THEN 0 ELSE 1 END', [
                CampaignApplicationStatus::Pending->value,
            ])
            ->orderByDesc('campaign_applications.id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => CampaignApplicationListItemResource::collection($paginator->items())->resolve($request),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                // The TAB BADGE's number, and it counts PENDING ONLY.
                //
                // ⚠ Never `applications_count` (the `withCount('applications')`
                // on the creator-facing card): that count is unfiltered by design
                // — it is INTEREST semantics, "how many creators applied", which
                // is what a creator weighing their odds needs. A badge asking the
                // agency to act must count only rows that can be acted on, and
                // conflating the two would show a permanent unclearable badge on
                // every campaign that ever had an application.
                'pending_total' => $this->pendingCount($campaign, $agency),
            ],
        ]);
    }

    /**
     * POST /api/v1/agencies/{agency}/campaigns/{campaign}/applications/{application}/accept
     *
     * ACCEPT = a real offer, creating a standard invitation (D2).
     *
     * The intent, stated once so the code below reads as its consequence: an
     * applicant who is accepted must be byte-indistinguishable downstream from a
     * cold invitee — same `invited` row, same audit verb, same event, same board
     * card, same message thread — and must still be able to DECLINE the actual
     * offer. Applying is interest, not consent to terms.
     *
     * ── The gate list, and the one leg deliberately dropped ─────────────────
     *
     * Reused from the direct-invite path: the `invite` ability, the approved-creator
     * leg, the agency-wide hard-blacklist re-check (a blacklist may POSTDATE the
     * application), and the availability conflict as a 409 the agency re-submits
     * past with `acknowledged: true`.
     *
     * ⚠ DROPPED: the `is_discoverable` leg. AH-051's ruling verbatim — a browsing
     * preference is not an eligibility gate. A creator who applied and has since
     * hidden themselves from discovery has expressed MORE interest in this
     * campaign, not less, and 404ing the agency's accept would be the platform
     * refusing to complete a deal both sides asked for.
     *
     * ── Atomicity ───────────────────────────────────────────────────────────
     *
     * ONE transaction wraps the application flip, its `responded_at`, the
     * assignment write, the audit rows and the event. The dual-emit runs AFTER it
     * returns, never inside: `after_commit => false` in `config/queue.php` means a
     * mail queued inside an open transaction is visible to a worker immediately,
     * so a rollback would leave a creator told they were accepted for an
     * assignment that does not exist.
     */
    public function accept(
        AcceptApplicationRequest $request,
        Agency $agency,
        Campaign $campaign,
        CampaignApplication $application,
        AssignmentInviteGate $gate,
        AssignmentOfferAttachmentUploadService $offerUploads,
        CampaignAssignmentStateMachine $machine,
        CampaignInvitationService $invitations,
        CampaignApplicationNotifier $notifier,
    ): JsonResponse {
        $this->assertBelongsToAgency($campaign, $agency);
        Gate::authorize('invite', $campaign);
        $this->assertApplicationBelongsToCampaign($application, $campaign);

        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();

        // Pending-only. A re-accept must not write a second assignment or stamp a
        // second `responded_at` (§5.6) — the enum has no state machine and its
        // docblock puts the source guard at the call site, which is here.
        if ($application->status !== CampaignApplicationStatus::Pending) {
            return $this->refuseNotPending($application);
        }

        $offer = AssignmentOffer::fromValidated($validated);

        // The attachment isolation backstop, identical to the invite path: the
        // upload_id must sit under THIS campaign's offer prefix and exist.
        /** @var array{upload_id: string, name: string, mime_type: string, size_bytes: int}|null $attachment */
        $attachment = $validated['attachment'] ?? null;

        if ($attachment !== null) {
            try {
                $offerUploads->assertUploadBelongs($agency, $campaign, (string) $attachment['upload_id']);
            } catch (RuntimeException $e) {
                return ErrorResponse::single(
                    $request,
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    'assignment.attachment_invalid',
                    $e->getMessage(),
                );
            }
        }

        $creator = $application->creator;

        // The approved leg, kept. ⚠ A 422 here, not the invite path's 404: that
        // 404 exists so a DISCOVERY caller cannot probe for a creator's
        // existence, and the agency already holds this creator's application and
        // roster row. Hiding the reason would only make an un-actionable state
        // look like a broken page.
        if (! $creator instanceof Creator || $creator->application_status !== ApplicationStatus::Approved) {
            return response()->json([
                'message' => 'This creator\'s account is no longer approved, so the application cannot be accepted.',
                'meta' => ['code' => 'application.creator_not_approved'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The hard-blacklist re-check. The application may predate the blacklist
        // by weeks, and this is the one gate whose answer can have changed since
        // the creator applied.
        if ($gate->isHardBlacklisted($campaign, $creator->id)) {
            return response()->json([
                'message' => 'This creator is hard-blacklisted and cannot be invited to this campaign.',
                'errors' => ['blacklist' => ['This creator is hard-blacklisted and cannot be invited to this campaign.']],
                'meta' => ['code' => 'assignment.blacklisted'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The D3 pair-collision matrix, read BEFORE the transaction so a refusal
        // costs nothing: no assignment → create; a DECLINED one → the AH-035
        // re-offer edge (the machinery exists for exactly this); anything else →
        // 422, naming the engagement.
        $existing = CampaignAssignment::query()
            ->where('campaign_id', $campaign->id)
            ->where('creator_id', $creator->id)
            ->first();

        if ($existing instanceof CampaignAssignment && $existing->status !== AssignmentStatus::Declined) {
            return response()->json([
                'message' => 'This creator is already engaged on this campaign.',
                'meta' => [
                    'code' => 'application.already_engaged',
                    // Naming the engagement is the difference between a dead end
                    // and an operator knowing where to look.
                    'assignment_status' => $existing->status->value,
                    'assignment_id' => $existing->ulid,
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The availability conflict — a soft warn, not a block. The signal
        // survives from the invite path so the accept dialog can surface it and
        // the agency can re-submit past it.
        $acknowledged = (bool) ($validated['acknowledged'] ?? false);

        if (! $acknowledged) {
            $conflict = $gate->availabilityConflict($campaign, $creator);

            if ($conflict->hasConflict) {
                return response()->json([
                    'message' => 'This creator has an availability conflict over the campaign window.',
                    'meta' => ['code' => 'assignment.availability_conflict'],
                    'conflict' => [
                        'creator_id' => $creator->ulid,
                        'conflicts' => array_map(static fn ($occurrence): array => [
                            'starts_at' => $occurrence->startsAt->toIso8601String(),
                            'ends_at' => $occurrence->endsAt->toIso8601String(),
                            'reason' => $occurrence->block->reason,
                        ], $conflict->conflicts),
                    ],
                ], Response::HTTP_CONFLICT);
            }
        }

        $reoffered = $existing instanceof CampaignAssignment;

        $assignment = DB::transaction(function () use (
            $application,
            $existing,
            $agency,
            $campaign,
            $creator,
            $offer,
            $actor,
            $machine,
            $invitations,
        ): CampaignAssignment {
            $this->markAccepted($application);

            if ($existing instanceof CampaignAssignment) {
                // AH-035's edge, re-used rather than re-implemented: the SAME row
                // flips declined → invited carrying the fresh offer, so the
                // message thread, the board card and the history survive and the
                // agency still sees the "declined then re-invited" tag.
                return $machine->reofferAfterDecline(
                    $existing,
                    $offer->agreedFeeMinorUnits,
                    $offer->agreedFeeCurrency,
                    $offer->feePer,
                    $offer->offerDescription,
                    $offer->attachmentForReoffer(),
                    $actor,
                );
            }

            return $invitations->invite($agency, $campaign, $creator, $offer, $actor);
        });

        // AFTER the commit (C1). Both legs of the emission, from one place.
        $notifier->accepted($application->setRelation('campaign', $campaign), $assignment->ulid);

        return (new CampaignAssignmentResource($assignment->load('creator:id,ulid,display_name')))
            ->response()
            ->setStatusCode($reoffered ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    /**
     * POST /api/v1/agencies/{agency}/campaigns/{campaign}/applications/{application}/reject
     *
     * REJECT = the agency's polite no (D4). No body, no reason, no migration.
     *
     * ⚠ There is deliberately NO agency-reason field, and the three-way argument
     * for one was heard: an admin-style mandatory reason, an optional one, and
     * none. None is the cheapest HONEST option. The creator-facing copy is a kind
     * generic "not selected" in all 24 locales regardless of what an agency might
     * type, so a stored reason would be an explanation nobody reads attached to a
     * decision nobody can appeal — the creator cannot re-apply either way, since
     * the retained terminal row occupies the unique pair. The audit row plus its
     * actor is the internal record, which is what a dispute actually needs.
     */
    public function reject(
        Request $request,
        Agency $agency,
        Campaign $campaign,
        CampaignApplication $application,
        CampaignApplicationNotifier $notifier,
    ): JsonResponse {
        $this->assertBelongsToAgency($campaign, $agency);
        // The same execute ability as accept (Q4): whoever may put an offer in
        // front of a creator is whoever may answer their application.
        Gate::authorize('invite', $campaign);
        $this->assertApplicationBelongsToCampaign($application, $campaign);

        // Pending-only, hand-written (§5.6): a second reject must not stamp a
        // second `responded_at` — the timestamp records when the agency answered,
        // and re-answering does not move that moment.
        if ($application->status !== CampaignApplicationStatus::Pending) {
            return $this->refuseNotPending($application);
        }

        DB::transaction(function () use ($application): void {
            $this->markRejected($application, ApplicationRejectionCause::AgencyRejected);
        });

        // AFTER the commit (C1).
        $notifier->rejected(
            $application->setRelation('campaign', $campaign),
            ApplicationRejectionCause::AgencyRejected,
        );

        return response()->json([
            'data' => [
                'id' => $application->ulid,
                'type' => 'campaign_application',
                'attributes' => [
                    'status' => $application->status->value,
                    'responded_at' => $application->responded_at?->toIso8601String(),
                ],
            ],
            'meta' => ['code' => 'application.rejected'],
        ]);
    }

    /**
     * The application flip every accept path shares — the HTTP accept, and (via
     * the invitation service's caller) the direct-invite convergence hook.
     *
     * MUST run inside the caller's transaction: the flip and the assignment write
     * are one fact, and an accepted application with no assignment is the torn
     * state review priority 1 exists to prove impossible.
     */
    private function markAccepted(CampaignApplication $application): void
    {
        $application->status = CampaignApplicationStatus::Accepted;
        $application->responded_at = now();
        $application->save();

        Audit::log(
            action: AuditAction::CampaignApplicationAccepted,
            subject: $application,
            metadata: [
                'campaign_id' => $application->campaign_id,
                'creator_id' => $application->creator_id,
            ],
            // Explicit, never inferred from ambient tenancy — the same call runs
            // from a queued context in the auto-reject sibling below.
            agencyId: $application->agency_id,
        );
    }

    /**
     * The rejection flip, shared by the agency's reject and (through
     * {@see AutoRejectPendingApplicationsJob}) the
     * campaign-terminal auto-reject. The cause is recorded in the audit metadata
     * because the row is otherwise identical and "who/what closed this" is the
     * only question a reader of the log will have.
     */
    private function markRejected(CampaignApplication $application, ApplicationRejectionCause $cause): void
    {
        $application->status = CampaignApplicationStatus::Rejected;
        $application->responded_at = now();
        $application->save();

        Audit::log(
            action: AuditAction::CampaignApplicationRejected,
            subject: $application,
            metadata: [
                'campaign_id' => $application->campaign_id,
                'creator_id' => $application->creator_id,
                'cause' => $cause->value,
            ],
            agencyId: $application->agency_id,
        );
    }

    /**
     * @return JsonResponse the §5.6 refusal for an already-answered application
     */
    private function refuseNotPending(CampaignApplication $application): JsonResponse
    {
        return response()->json([
            'message' => 'This application has already been answered.',
            'meta' => [
                'code' => 'application.not_pending',
                'status' => $application->status->value,
            ],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * An application from another campaign — even one of the same agency — is a
     * 404 on this campaign's URL. The tenancy scope alone would let it through,
     * so this check is the one that separates them.
     */
    private function assertApplicationBelongsToCampaign(CampaignApplication $application, Campaign $campaign): void
    {
        if ($application->campaign_id !== $campaign->id) {
            abort(404);
        }
    }

    /**
     * The unknown-value convention (`CampaignDraftController::index()`): a
     * recognised status filters, an unrecognised one returns an EMPTY page rather
     * than silently returning everything. A typo'd filter that quietly widens the
     * result set is the failure mode this prevents.
     *
     * @param  Builder<CampaignApplication>  $query
     */
    private function applyStatusFilter(Builder $query, Request $request): void
    {
        $statusInput = $request->query('status');

        if (! is_string($statusInput) || $statusInput === '') {
            return;
        }

        $status = CampaignApplicationStatus::tryFrom($statusInput);

        if ($status === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('campaign_applications.status', $status->value);
    }

    /**
     * The pending count for the whole campaign — deliberately NOT the paginated
     * page's pending rows, and deliberately NOT affected by the `status` filter:
     * the badge means "this many decisions are waiting", which does not change
     * because the operator is currently looking at the rejected ones.
     */
    private function pendingCount(Campaign $campaign, Agency $agency): int
    {
        return CampaignApplication::query()
            ->where('campaign_applications.campaign_id', $campaign->id)
            ->where('campaign_applications.agency_id', $agency->id)
            ->where('campaign_applications.status', CampaignApplicationStatus::Pending->value)
            ->count();
    }

    /**
     * A campaign that is not this agency's is a flat 404 — never a 403, which
     * would confirm the campaign exists (§5.4 non-fingerprinting).
     */
    private function assertBelongsToAgency(Campaign $campaign, Agency $agency): void
    {
        if ($campaign->agency_id !== $agency->id) {
            abort(404);
        }
    }
}
