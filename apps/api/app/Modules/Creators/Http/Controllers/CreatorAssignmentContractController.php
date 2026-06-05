<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Exceptions\AssignmentTransitionException;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use App\Modules\Creators\Enums\ContractStatus;
use App\Modules\Creators\Features\PerCampaignContractEnabled;
use App\Modules\Creators\Http\Resources\ContractResource;
use App\Modules\Creators\Models\Contract;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * Creator-self contract accept (contract-bridge chunk, D-2/D-4).
 *
 *   POST /api/v1/creators/me/assignments/{assignment}/contract/accept
 *
 * Stamps `signed_at` on the pending per-campaign Contract, then calls
 * `contract()` (`accepted → contracted`, sets `contract_id`). STOPS at
 * `contracted` — no `startProducing` auto-chain (D-2).
 */
final class CreatorAssignmentContractController
{
    public function accept(Request $request, string $assignment, CampaignAssignmentStateMachine $machine): JsonResponse
    {
        if (! Feature::active(PerCampaignContractEnabled::NAME)) {
            return ErrorResponse::single(
                $request,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'assignment.per_campaign_contract_disabled',
                'The per-campaign contract flow is not enabled.',
            );
        }

        $creator = $this->requireCreator($request);

        /** @var User $user */
        $user = $request->user();

        $model = CampaignAssignment::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('creator_id', $creator->id)
            ->where('ulid', $assignment)
            ->first();

        if ($model === null) {
            abort(response()->json([
                'errors' => [[
                    'status' => '404',
                    'code' => 'assignment.not_found',
                    'detail' => 'No assignment found.',
                ]],
            ], 404));
        }

        if ($model->status !== AssignmentStatus::Accepted) {
            return ErrorResponse::single(
                $request,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'assignment.not_accepted',
                'This assignment is not awaiting contract acceptance.',
            );
        }

        $contract = Contract::query()
            ->where('subject_type', Contract::SUBJECT_CAMPAIGN_ASSIGNMENT)
            ->where('subject_id', $model->id)
            ->where('status', ContractStatus::Sent)
            ->first();

        if ($contract === null) {
            return ErrorResponse::single(
                $request,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'contract.not_found',
                'No contract is awaiting your acceptance.',
            );
        }

        try {
            DB::transaction(function () use ($contract, $model, $machine, $user, $creator, $request): void {
                $contract->status = ContractStatus::Signed;
                $contract->signed_at = now();
                $contract->signed_by_creator_id = $creator->id;
                $contract->signed_signature_data = [
                    'method' => Contract::METHOD_CLICK_THROUGH,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'accepted_at' => now()->toIso8601String(),
                ];
                $contract->save();

                $machine->contract($model, $contract, $user);
            });
        } catch (AssignmentTransitionException $e) {
            return ErrorResponse::single(
                $request,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $e->errorCode,
                $e->getMessage(),
            );
        }

        $fresh = $model->fresh();

        return response()->json([
            'data' => [
                'type' => 'campaign_assignment',
                'id' => $model->ulid,
                'attributes' => [
                    'status' => ($fresh ?? $model)->status->value,
                ],
                'relationships' => [
                    'contract' => (new ContractResource($contract->fresh() ?? $contract))->resolve($request),
                ],
            ],
            'meta' => ['code' => 'assignment.contracted'],
        ]);
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
