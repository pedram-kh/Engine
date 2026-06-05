<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Mail\ContractAttachedMail;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\AssignmentContractUploadService;
use App\Modules\Creators\Enums\ContractKind;
use App\Modules\Creators\Enums\ContractStatus;
use App\Modules\Creators\Features\ContractSigningEnabled;
use App\Modules\Creators\Http\Resources\ContractResource;
use App\Modules\Creators\Models\Contract;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;
use RuntimeException;

/**
 * Agency-side per-campaign contract attach (contract-bridge chunk, D-1/D-6/D-9).
 *
 *   POST …/assignments/{assignment}/contract/media/init     presigned PDF init
 *   POST …/assignments/{assignment}/contract/media/complete presigned verify
 *   POST …/assignments/{assignment}/contract/attach         issue the contract
 *
 * Creates a `per_campaign` Contract row (`status=sent`) and notifies the
 * creator. Does NOT call `contract()` — the creator accept endpoint drives
 * `accepted → contracted` (D-2).
 */
final class CampaignAssignmentContractController
{
    public function __construct(
        private readonly AssignmentContractUploadService $uploads,
    ) {}

    public function initMedia(Request $request, Agency $agency, Campaign $campaign, CampaignAssignment $assignment): JsonResponse
    {
        $this->assertContext($campaign, $agency, $assignment);
        Gate::authorize('attachContract', $campaign);
        if ($gate = $this->flagGate($request)) {
            return $gate;
        }

        $request->validate([
            'mime_type' => ['required', 'string'],
            'declared_bytes' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $payload = $this->uploads->initiatePresignedUpload(
                $agency,
                $assignment,
                (string) $request->string('mime_type'),
                (int) $request->integer('declared_bytes'),
            );
        } catch (RuntimeException $e) {
            return ErrorResponse::single($request, 422, 'contract.presign_failed', $e->getMessage());
        }

        return response()->json(['data' => $payload]);
    }

    public function completeMedia(Request $request, Agency $agency, Campaign $campaign, CampaignAssignment $assignment): JsonResponse
    {
        $this->assertContext($campaign, $agency, $assignment);
        Gate::authorize('attachContract', $campaign);
        if ($gate = $this->flagGate($request)) {
            return $gate;
        }

        $request->validate([
            'upload_id' => ['required', 'string'],
        ]);

        try {
            $path = $this->uploads->completePresignedUpload(
                $agency,
                $assignment,
                (string) $request->string('upload_id'),
            );
        } catch (RuntimeException $e) {
            return ErrorResponse::single($request, 422, 'contract.complete_failed', $e->getMessage());
        }

        return response()->json([
            'data' => [
                'storage_path' => $path,
            ],
        ], 201);
    }

    public function attach(Request $request, Agency $agency, Campaign $campaign, CampaignAssignment $assignment): JsonResponse
    {
        $this->assertContext($campaign, $agency, $assignment);
        Gate::authorize('attachContract', $campaign);
        if ($gate = $this->flagGate($request)) {
            return $gate;
        }

        if ($assignment->status !== AssignmentStatus::Accepted) {
            return ErrorResponse::single(
                $request,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'assignment.not_accepted',
                'Only an accepted assignment can receive a contract.',
            );
        }

        if ($this->pendingContract($assignment) !== null) {
            return ErrorResponse::single(
                $request,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'contract.already_attached',
                'A contract is already awaiting creator acceptance.',
            );
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body_markdown' => ['nullable', 'string', 'max:50000'],
            'body_pdf_path' => ['nullable', 'string', 'max:500'],
        ]);

        $bodyMarkdown = isset($validated['body_markdown']) ? trim((string) $validated['body_markdown']) : '';
        $bodyPdfPath = isset($validated['body_pdf_path']) ? trim((string) $validated['body_pdf_path']) : '';

        if ($bodyMarkdown === '' && $bodyPdfPath === '') {
            return ErrorResponse::single(
                $request,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'contract.document_required',
                'Provide contract terms (markdown) and/or an uploaded PDF.',
            );
        }

        if ($bodyPdfPath !== '') {
            $expectedPrefix = sprintf(
                'agencies/%s/assignments/%s/contracts/',
                $agency->ulid,
                $assignment->ulid,
            );
            if (! str_starts_with($bodyPdfPath, $expectedPrefix)) {
                return ErrorResponse::single(
                    $request,
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    'contract.invalid_pdf_path',
                    'The PDF path does not belong to this assignment.',
                );
            }
        }

        /** @var User $actor */
        $actor = $request->user();

        $contract = Contract::query()->create([
            'agency_id' => $agency->id,
            'kind' => ContractKind::PerCampaign,
            'subject_type' => Contract::SUBJECT_CAMPAIGN_ASSIGNMENT,
            'subject_id' => $assignment->id,
            'version' => 1,
            'title' => (string) $validated['title'],
            'body_markdown' => $bodyMarkdown,
            'body_pdf_path' => $bodyPdfPath !== '' ? $bodyPdfPath : null,
            'signature_provider' => Contract::PROVIDER_INTERNAL,
            'status' => ContractStatus::Sent,
            'sent_at' => now(),
            'created_by_user_id' => $actor->id,
        ]);

        $this->notifyCreator($assignment, $contract);

        return response()->json([
            'data' => (new ContractResource($contract))->resolve($request),
            'meta' => ['code' => 'contract.attached'],
        ], 201);
    }

    private function notifyCreator(CampaignAssignment $assignment, Contract $contract): void
    {
        $creator = $assignment->creator;
        $campaign = $assignment->campaign;
        $recipient = $creator?->user;

        if ($creator === null || $campaign === null || ! $recipient instanceof User || $recipient->email === '') {
            return;
        }

        Mail::to($recipient->email)
            ->locale($recipient->preferred_language ?: 'en')
            ->queue(new ContractAttachedMail(
                creatorName: $creator->display_name ?? $recipient->name,
                campaignName: $campaign->name,
                assignmentUlid: $assignment->ulid,
            ));
    }

    private function pendingContract(CampaignAssignment $assignment): ?Contract
    {
        return Contract::query()
            ->where('subject_type', Contract::SUBJECT_CAMPAIGN_ASSIGNMENT)
            ->where('subject_id', $assignment->id)
            ->where('status', ContractStatus::Sent)
            ->first();
    }

    private function flagGate(Request $request): ?JsonResponse
    {
        if (Feature::active(ContractSigningEnabled::NAME)) {
            return null;
        }

        return ErrorResponse::single(
            $request,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'assignment.contract_signing_disabled',
            'Contract signing is not enabled.',
        );
    }

    private function assertContext(Campaign $campaign, Agency $agency, CampaignAssignment $assignment): void
    {
        if ($campaign->agency_id !== $agency->id) {
            abort(404);
        }

        if ($assignment->campaign_id !== $campaign->id) {
            abort(404);
        }
    }
}
