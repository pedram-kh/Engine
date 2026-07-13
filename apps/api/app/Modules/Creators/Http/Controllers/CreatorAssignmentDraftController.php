<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\DraftReviewStatus;
use App\Modules\Campaigns\Enums\PostedContentVerificationStatus;
use App\Modules\Campaigns\Http\Resources\CampaignDraftResource;
use App\Modules\Campaigns\Http\Resources\CampaignPostedContentResource;
use App\Modules\Campaigns\Jobs\VerifyPostedContentJob;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Models\CampaignDraft;
use App\Modules\Campaigns\Models\CampaignPostedContent;
use App\Modules\Campaigns\Services\AssignmentOfferAttachmentUploadService;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use App\Modules\Creators\Enums\ContractStatus;
use App\Modules\Creators\Enums\SocialPlatform;
use App\Modules\Creators\Features\PerCampaignContractEnabled;
use App\Modules\Creators\Http\Resources\ContractResource;
use App\Modules\Creators\Models\Contract;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\PortfolioUploadService;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;
use RuntimeException;

/**
 * The CREATOR submission surface for a campaign assignment (Sprint 9 Chunk 1).
 * The submission half of the submission→review seam — drafts (submit/resubmit)
 * + the self-reported posted content, plus the presigned media pipeline that
 * feeds a draft. Mirrors {@see CreatorAssignmentController} exactly:
 *
 *   GET    /api/v1/creators/me/assignments/{assignment}                     show (D-9)
 *   POST   /api/v1/creators/me/assignments/{assignment}/drafts              submit/resubmit (D-5/6)
 *   POST   /api/v1/creators/me/assignments/{assignment}/drafts/media/init   presigned init (D-8)
 *   POST   /api/v1/creators/me/assignments/{assignment}/drafts/media/complete  presigned verify (D-8)
 *   POST   /api/v1/creators/me/assignments/{assignment}/posted-content      mark posted (D-7)
 *
 * ⚠ The BelongsToAgency global scope is bypassed deliberately (the documented
 * justified HTTP bypass, identical to {@see CreatorAssignmentController}): the
 * caller is a CREATOR who may hold assignments from many agencies. Ownership
 * is STRUCTURAL — a non-owned ULID is simply not found (404).
 *
 * Status is flipped ONLY via {@see CampaignAssignmentStateMachine} (the sole
 * authority). The endpoints fail-closed on the legal source states:
 *   - drafts: producing (submit) / contracted|revision_requested (startProducing
 *     first, then submit — the two-step machine path, D-4/D-6); anything else 422.
 *   - posted-content: approved only; anything else 422.
 *
 * Chunk 1 STOPS at `posted` / `verification_status=pending` — there is no
 * review (approve/revision/reject) and no verifyLive here (Chunk 2).
 */
final class CreatorAssignmentDraftController
{
    /**
     * The namespace partition for draft media on the private `media` disk
     * (`creators/{ulid}/drafts/…`), distinct from portfolio uploads.
     */
    private const string MEDIA_NAMESPACE = 'drafts';

    public function __construct(
        private readonly PortfolioUploadService $uploads,
    ) {}

    /**
     * The per-assignment detail payload the creator detail route consumes
     * (D-9): the assignment + its full draft version history + any posted
     * content. Read-only — no transition.
     */
    public function show(Request $request, string $assignment): JsonResponse
    {
        $creator = $this->requireCreator($request);
        $model = $this->resolveOwnedAssignment($creator, $assignment);

        $drafts = CampaignDraft::query()
            ->where('assignment_id', $model->id)
            ->orderByDesc('version')
            ->get();

        $posted = CampaignPostedContent::query()
            ->where('assignment_id', $model->id)
            ->orderByDesc('id')
            ->get();

        $contract = Contract::query()
            ->where('subject_type', Contract::SUBJECT_CAMPAIGN_ASSIGNMENT)
            ->where('subject_id', $model->id)
            ->whereIn('status', [ContractStatus::Sent, ContractStatus::Signed])
            ->orderByDesc('id')
            ->first();

        $campaign = $model->campaign;
        $brand = $campaign?->brand;

        return response()->json([
            'data' => [
                'id' => $model->ulid,
                'type' => 'campaign_assignment',
                'attributes' => [
                    'status' => $model->status->value,
                    'agreed_fee_minor_units' => $model->agreed_fee_minor_units,
                    'agreed_fee_currency' => $model->agreed_fee_currency,
                    // Invite-offer context (invite-offer-details batch); the
                    // signed URL inherits this owner-scoped surface's authz.
                    'fee_per' => $model->fee_per,
                    'offer_description' => $model->offer_description,
                    'offer_attachment' => $model->offer_attachment_path !== null ? [
                        'name' => $model->offer_attachment_name,
                        'mime_type' => $model->offer_attachment_mime,
                        'size_bytes' => $model->offer_attachment_size_bytes,
                        'url' => AssignmentOfferAttachmentUploadService::signedViewUrl($model->offer_attachment_path),
                    ] : null,
                    'countered_fee_minor_units' => $model->countered_fee_minor_units,
                    'countered_fee_currency' => $model->countered_fee_currency,
                    'deliverables' => $model->deliverables,
                    'posting_due_at' => $model->posting_due_at?->toIso8601String(),
                    'invited_at' => $model->invited_at?->toIso8601String(),
                    'submitted_draft_at' => $model->submitted_draft_at?->toIso8601String(),
                    'approved_at' => $model->approved_at?->toIso8601String(),
                    'posted_at' => $model->posted_at?->toIso8601String(),
                    'campaign' => $campaign !== null ? [
                        'id' => $campaign->ulid,
                        'name' => $campaign->name,
                        'posting_window_starts_at' => ($campaign->posting_window_starts_at ?? $campaign->starts_at)?->toIso8601String(),
                        'posting_window_ends_at' => ($campaign->posting_window_ends_at ?? $campaign->ends_at)?->toIso8601String(),
                        'brand_name' => $brand?->name,
                    ] : null,
                ],
                'relationships' => [
                    'drafts' => CampaignDraftResource::collection($drafts)->resolve($request),
                    'posted_content' => CampaignPostedContentResource::collection($posted)->resolve($request),
                    'contract' => $contract !== null
                        ? (new ContractResource($contract))->resolve($request)
                        : null,
                ],
            ],
            'meta' => [
                // The per-campaign manual-contract flag (NOT the e-sign vendor
                // flag) — the assignment-detail surface reflects whether the
                // per-campaign flow is available (D-5 key rename).
                'per_campaign_contract_enabled' => Feature::active(PerCampaignContractEnabled::NAME),
                // Whether THIS campaign requires a per-campaign contract
                // (toggle-off-flow chunk, D3). The creator copy consults this so
                // an OFF campaign never shows "the agency will send a contract".
                // Belt-and-suspenders: with the D2 auto-advance an OFF assignment
                // should never sit at `accepted`, but a residual row is covered.
                'requires_per_campaign_contract' => $campaign !== null && $campaign->requires_per_campaign_contract,
            ],
        ]);
    }

    /**
     * Initiate a presigned draft-media upload (D-8). Reuses the
     * PortfolioUploadService presigned mechanics under the `drafts` namespace
     * (no portfolio capacity cap; image + video MIME accepted). The client
     * PUTs the bytes to the returned URL with the EXACT signed Content-Type,
     * then calls complete().
     */
    public function initMedia(Request $request, string $assignment): JsonResponse
    {
        $request->validate([
            'mime_type' => ['required', 'string'],
            'declared_bytes' => ['required', 'integer', 'min:1'],
        ]);

        $creator = $this->requireCreator($request);
        // Resolve ownership (404 on a non-owned ULID) before issuing a URL.
        $this->resolveOwnedAssignment($creator, $assignment);

        try {
            $payload = $this->uploads->initiatePresignedUpload(
                $creator,
                (string) $request->string('mime_type'),
                (int) $request->integer('declared_bytes'),
                self::MEDIA_NAMESPACE,
            );
        } catch (RuntimeException $e) {
            return ErrorResponse::single($request, 422, 'draft.presign_failed', $e->getMessage());
        }

        return response()->json(['data' => $payload]);
    }

    /**
     * Verify a presigned draft-media upload landed and return the storage
     * path the SPA will reference in the draft submission (D-8).
     */
    public function completeMedia(Request $request, string $assignment): JsonResponse
    {
        $request->validate([
            'upload_id' => ['required', 'string'],
        ]);

        $creator = $this->requireCreator($request);
        $this->resolveOwnedAssignment($creator, $assignment);

        try {
            $path = $this->uploads->completePresignedUpload(
                $creator,
                (string) $request->string('upload_id'),
                self::MEDIA_NAMESPACE,
            );
        } catch (RuntimeException $e) {
            return ErrorResponse::single($request, 422, 'draft.complete_failed', $e->getMessage());
        }

        return response()->json([
            'data' => [
                'storage_path' => $path,
            ],
        ], 201);
    }

    /**
     * Submit (version 1) or resubmit (version N+1) a draft (D-5/D-6).
     *
     * Creates a `campaign_drafts` row, then drives the machine to
     * `draft_submitted`:
     *   - producing            → submitDraft()
     *   - contracted           → startProducing() → submitDraft() (first work, D-4)
     *   - revision_requested   → startProducing() → submitDraft() (resubmit, D-6)
     *   - anything else        → 422 assignment.not_producible (fail-closed)
     *
     * The two-step machine guard (revision_requested → producing →
     * draft_submitted) is honoured — never a direct resubmit (D-6). The draft
     * id + version + media count ride the `assignment.draft_submitted`
     * transition audit; free text (caption) is excluded (D-3).
     */
    public function submitDraft(Request $request, string $assignment, CampaignAssignmentStateMachine $machine): JsonResponse
    {
        $validated = $request->validate([
            'caption' => ['nullable', 'string', 'max:2200'],
            'hashtags' => ['nullable', 'array'],
            'hashtags.*' => ['string', 'max:100'],
            'mentions' => ['nullable', 'array'],
            'mentions.*' => ['string', 'max:100'],
            'media' => ['required', 'array', 'min:1'],
            'media.*.s3_path' => ['required', 'string'],
            'media.*.mime_type' => ['required', 'string'],
            'media.*.kind' => ['required', 'string', Rule::in(['image', 'video'])],
            'media.*.thumbnail_path' => ['nullable', 'string'],
            'media.*.duration_seconds' => ['nullable', 'integer', 'min:1'],
            // External reference links (draft-composer facelift) — the same
            // url+name pair the relationship-messaging composer sends. http(s)
            // is enforced by the url rule's scheme list.
            'links' => ['nullable', 'array', 'max:10'],
            'links.*.url' => ['required', 'string', 'url:http,https', 'max:2048'],
            'links.*.name' => ['nullable', 'string', 'max:255'],
        ]);

        $creator = $this->requireCreator($request);

        /** @var User $user */
        $user = $request->user();

        $model = $this->resolveOwnedAssignment($creator, $assignment);

        // Fail-closed: a draft may only be submitted from a state the machine
        // can drive to draft_submitted (directly, or via startProducing).
        $producible = [AssignmentStatus::Producing, AssignmentStatus::Contracted, AssignmentStatus::RevisionRequested];
        if (! in_array($model->status, $producible, true)) {
            return ErrorResponse::single(
                $request,
                422,
                'assignment.not_producible',
                'This assignment is not ready for a draft submission.',
            );
        }

        // Structural ownership of every media path: a creator may only
        // reference media under their OWN drafts prefix (defense-in-depth on
        // top of the init/complete creator-scoping).
        $expectedPrefix = sprintf('creators/%s/%s/', $creator->ulid, self::MEDIA_NAMESPACE);
        /** @var list<array<string, mixed>> $media */
        $media = $validated['media'];
        foreach ($media as $item) {
            if (! str_starts_with((string) $item['s3_path'], $expectedPrefix)) {
                return ErrorResponse::single(
                    $request,
                    422,
                    'draft.media_invalid',
                    'A media attachment does not belong to this creator.',
                    source: ['pointer' => '/data/attributes/media'],
                );
            }
        }

        $draft = DB::transaction(function () use ($model, $creator, $user, $validated, $media, $machine): CampaignDraft {
            $version = (int) (CampaignDraft::query()
                ->where('assignment_id', $model->id)
                ->max('version') ?? 0) + 1;

            $draft = CampaignDraft::create([
                'assignment_id' => $model->id,
                'version' => $version,
                'submitted_by_creator_id' => $creator->id,
                'submitted_at' => now(),
                'caption' => $validated['caption'] ?? null,
                'hashtags' => $validated['hashtags'] ?? null,
                'mentions' => $validated['mentions'] ?? null,
                'media_attachments' => array_map(static fn (array $item): array => [
                    's3_path' => $item['s3_path'],
                    'mime_type' => $item['mime_type'],
                    'kind' => $item['kind'],
                    'thumbnail_path' => $item['thumbnail_path'] ?? null,
                    'duration_seconds' => $item['duration_seconds'] ?? null,
                ], $media),
                'links' => array_values(array_map(static fn (array $link): array => [
                    'url' => $link['url'],
                    'name' => ($link['name'] ?? '') === '' ? null : $link['name'],
                ], $validated['links'] ?? [])) ?: null,
                'review_status' => DraftReviewStatus::Pending,
            ]);

            // The two-step machine path: lift contracted / revision_requested
            // up to producing first, then submit (D-4/D-6). producing submits
            // directly. The machine owns both audit rows + events.
            if ($model->status !== AssignmentStatus::Producing) {
                $machine->startProducing($model, $user);
            }

            $machine->submitDraft($model, $user, context: [
                'draft_id' => $draft->ulid,
                'version' => $version,
                'media_count' => count($media),
                // Link URLs are free text — count only (the D-3 discipline).
                'link_count' => count($draft->links ?? []),
            ]);

            return $draft;
        });

        return response()->json([
            'data' => (new CampaignDraftResource($draft))->resolve($request),
            'meta' => ['code' => 'assignment.draft_submitted'],
        ], 201);
    }

    /**
     * Mark the assignment posted + record the self-reported post URL (D-7).
     * Creates a `campaign_posted_content` row (verification_status=pending),
     * then drives approved → posted via markPosted(). Fail-closed: only an
     * `approved` assignment may be marked posted. STOPS here — verifyLive is
     * Chunk 2.
     */
    public function submitPostedContent(Request $request, string $assignment, CampaignAssignmentStateMachine $machine): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['required', 'string', Rule::in(array_map(static fn (SocialPlatform $p): string => $p->value, SocialPlatform::cases()))],
            'post_url' => ['required', 'string', 'url', 'max:2048'],
        ]);

        $creator = $this->requireCreator($request);

        /** @var User $user */
        $user = $request->user();

        $model = $this->resolveOwnedAssignment($creator, $assignment);

        if ($model->status !== AssignmentStatus::Approved) {
            return ErrorResponse::single(
                $request,
                422,
                'assignment.not_approved',
                'This assignment has not been approved for posting.',
            );
        }

        $posted = DB::transaction(function () use ($model, $validated, $user, $machine): CampaignPostedContent {
            $posted = CampaignPostedContent::create([
                'assignment_id' => $model->id,
                'platform' => $validated['platform'],
                'post_url' => $validated['post_url'],
                'verification_status' => PostedContentVerificationStatus::Pending,
                'posted_at' => now(),
            ]);

            $machine->markPosted($model, $user, context: [
                'posted_content_id' => $posted->ulid,
                'platform' => $posted->platform,
            ]);

            return $posted;
        });

        return response()->json([
            'data' => (new CampaignPostedContentResource($posted))->resolve($request),
            'meta' => ['code' => 'assignment.posted_by_creator'],
        ], 201);
    }

    /**
     * Edit the self-reported post URL IN PLACE after a failed auto-verification
     * (verification-resolution chunk, ACT3/D-6). The creator fixes the URL on
     * the existing posted-content row; this resets `verification_status` to
     * `pending` (clearing any prior `verified_at`/`platform_post_id`) and
     * re-dispatches {@see VerifyPostedContentJob} — and BECAUSE the job is
     * idempotent (it bails unless `pending`), the reset-to-`pending` is exactly
     * what re-arms it. NO state transition — the assignment stays `posted`.
     *
     * Fail-closed: allowed ONLY when the assignment is `posted` AND the latest
     * posted-content row's verification FAILED (`not_found`/`mismatch`). The
     * agency's in-place resubmit request is a nudge, NOT a precondition — the
     * creator may fix a failed post whenever it is in that state.
     *
     * Audits the creator's mutation distinctly (`assignment.posted_content_updated`);
     * the free-text URL is NOT snapshotted (the hand-written-audit discipline, D-3).
     */
    public function updatePostedContent(Request $request, string $assignment): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['sometimes', 'string', Rule::in(array_map(static fn (SocialPlatform $p): string => $p->value, SocialPlatform::cases()))],
            'post_url' => ['required', 'string', 'url', 'max:2048'],
        ]);

        $creator = $this->requireCreator($request);
        $model = $this->resolveOwnedAssignment($creator, $assignment);

        // Fail-closed: only a `posted` assignment whose latest post failed
        // verification may be edited in place.
        if ($model->status !== AssignmentStatus::Posted) {
            return ErrorResponse::single(
                $request,
                422,
                'assignment.not_resolvable',
                'This assignment has no failed post to resubmit in place.',
            );
        }

        $posted = CampaignPostedContent::query()
            ->where('assignment_id', $model->id)
            ->orderByDesc('id')
            ->first();

        $failed = [PostedContentVerificationStatus::NotFound, PostedContentVerificationStatus::Mismatch];
        if ($posted === null || ! in_array($posted->verification_status, $failed, true)) {
            return ErrorResponse::single(
                $request,
                422,
                'assignment.not_resolvable',
                'This post has no failed verification to resubmit in place.',
            );
        }

        DB::transaction(function () use ($posted, $model, $validated): void {
            if (isset($validated['platform'])) {
                $posted->platform = (string) $validated['platform'];
            }
            $posted->post_url = (string) $validated['post_url'];
            // The reset re-arms the idempotent verification job.
            $posted->verification_status = PostedContentVerificationStatus::Pending;
            $posted->verified_at = null;
            $posted->platform_post_id = null;
            $posted->save();

            // The creator's mutation audits distinctly from the agency request
            // (ACT3's `assignment.resubmit_requested_in_place`). The free-text
            // post_url is excluded (D-3); the posted-content id + platform ride it.
            Audit::log(
                action: AuditAction::AssignmentPostedContentUpdated,
                subject: $model,
                metadata: ['posted_content_id' => $posted->ulid, 'platform' => $posted->platform],
            );
        });

        // Standalone re-verify dispatch (today the job only fires on
        // posted_by_creator). The job re-checks the social flag itself.
        VerifyPostedContentJob::dispatch($posted->ulid);

        return response()->json([
            'data' => (new CampaignPostedContentResource($posted->refresh()))->resolve($request),
            'meta' => ['code' => 'assignment.posted_content_updated'],
        ]);
    }

    /**
     * Resolve the assignment within the creator's OWN assignments (scope
     * bypassed). A non-owned ULID is simply not found (404) — the structural
     * owner-only guard.
     */
    private function resolveOwnedAssignment(Creator $creator, string $assignmentUlid): CampaignAssignment
    {
        $assignment = CampaignAssignment::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('creator_id', $creator->id)
            ->where('ulid', $assignmentUlid)
            ->with(['campaign:id,ulid,name,posting_window_starts_at,posting_window_ends_at,starts_at,ends_at,brand_id,requires_per_campaign_contract', 'campaign.brand:id,ulid,name'])
            ->first();

        if ($assignment === null) {
            abort(response()->json([
                'errors' => [[
                    'status' => '404',
                    'code' => 'assignment.not_found',
                    'detail' => 'No assignment found.',
                ]],
            ], 404));
        }

        return $assignment;
    }

    private function requireCreator(Request $request): Creator
    {
        /** @var User $user */
        $user = $request->user();
        $creator = $user->creator;

        if ($creator === null) {
            abort(response()->json([
                'errors' => [[
                    'status' => '404',
                    'code' => 'creator.not_found',
                    'detail' => 'No creator profile is associated with this user.',
                ]],
            ], 404));
        }

        return $creator;
    }
}
