<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Http\Requests\DeleteAutomationRuleRequest;
use App\Modules\Admin\Http\Requests\StoreAutomationRuleRequest;
use App\Modules\Admin\Http\Requests\ToggleAutomationRuleRequest;
use App\Modules\Admin\Http\Requests\UpdateAutomationRuleRequest;
use App\Modules\Admin\Http\Resources\AdminAutomationRuleResource;
use App\Modules\Admin\Models\AdminAutomationRule;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Enums\UserType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Platform automation rules — the operator's CRUD (AH-090, D6).
 *
 *   GET    /api/v1/admin/automation-rules
 *   POST   /api/v1/admin/automation-rules
 *   PATCH  /api/v1/admin/automation-rules/{rule}
 *   DELETE /api/v1/admin/automation-rules/{rule}
 *   POST   /api/v1/admin/automation-rules/{rule}/toggle
 *
 * Cross-agency / tenant-less BY DESIGN, exactly like the feature-flag toggle it
 * is modelled on: a rule is a platform capability, not an agency-scoped
 * resource. Gated by the platform_admin bounded bypass and allowlisted in
 * docs/security/tenancy.md §4.
 *
 * EVERY write carries a mandatory reason and an audit row — all four
 * `automation_rule.*` verbs requiresReason(). These rules write relationship
 * rows into live data without a human present at the moment of writing, so the
 * question "who configured this, and why" must always have an answer.
 *
 * Unpaginated: rules are operator-created, one at a time, through a form. There
 * will be tens, and a picker or a list that hides some of them is the bug class
 * docs/02-CONVENTIONS.md §3.5 now names.
 */
final class AdminAutomationRuleController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        $rules = AdminAutomationRule::query()->orderByDesc('id')->get();

        return response()->json([
            'data' => $rules
                ->map(fn (AdminAutomationRule $rule): array => (new AdminAutomationRuleResource(
                    $rule,
                    $this->resolveTargets($rules),
                ))->toArray($request))
                ->all(),
        ]);
    }

    public function store(StoreAutomationRuleRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        $rule = DB::transaction(function () use ($request): AdminAutomationRule {
            $rule = AdminAutomationRule::query()->create([
                'name' => $request->ruleName(),
                'event' => $request->event(),
                'action' => $request->action(),
                'config' => $request->config(),
                'enabled' => $request->enabled(),
            ]);

            Audit::log(
                action: AuditAction::AutomationRuleCreated,
                actor: $request->user(),
                subject: $rule,
                reason: $request->reason(),
                after: $this->snapshot($rule),
            );

            return $rule;
        });

        return response()->json(
            ['data' => (new AdminAutomationRuleResource($rule, $this->resolveTargets(collect([$rule]))))->toArray($request)],
            Response::HTTP_CREATED,
        );
    }

    public function update(UpdateAutomationRuleRequest $request, AdminAutomationRule $rule): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        $before = $this->snapshot($rule);

        DB::transaction(function () use ($request, $rule, $before): void {
            $rule->update([
                'name' => $request->ruleName(),
                'event' => $request->event(),
                'action' => $request->action(),
                'config' => $request->config(),
            ]);

            Audit::log(
                action: AuditAction::AutomationRuleUpdated,
                actor: $request->user(),
                subject: $rule,
                reason: $request->reason(),
                before: $before,
                after: $this->snapshot($rule->refresh()),
            );
        });

        return response()->json([
            'data' => (new AdminAutomationRuleResource($rule, $this->resolveTargets(collect([$rule]))))->toArray($request),
        ]);
    }

    /**
     * Enable / disable. Separate from `update()` because it is the operational
     * control an operator reaches for in a hurry — one field, one reason, no
     * chance of a stale form overwriting the rest of the row on the way.
     */
    public function toggle(ToggleAutomationRuleRequest $request, AdminAutomationRule $rule): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        $enabled = $request->enabled();
        $before = $rule->enabled;

        DB::transaction(function () use ($request, $rule, $enabled, $before): void {
            $rule->update(['enabled' => $enabled]);

            Audit::log(
                action: AuditAction::AutomationRuleToggled,
                actor: $request->user(),
                subject: $rule,
                reason: $request->reason(),
                before: ['enabled' => $before],
                after: ['enabled' => $enabled],
                metadata: ['rule_id' => $rule->id, 'enabled' => $enabled],
            );
        });

        return response()->json([
            'data' => (new AdminAutomationRuleResource($rule, $this->resolveTargets(collect([$rule]))))->toArray($request),
        ]);
    }

    /**
     * Hard delete (D7). Stops future fires; never unwinds past ones — the
     * relations a rule created are real relationships, not automation state.
     *
     * The `before` snapshot is the whole point of the audit row here: it is the
     * only remaining record of what the rule was and how often it fired.
     */
    public function destroy(DeleteAutomationRuleRequest $request, AdminAutomationRule $rule): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        $before = $this->snapshot($rule);

        DB::transaction(function () use ($request, $rule, $before): void {
            Audit::log(
                action: AuditAction::AutomationRuleDeleted,
                actor: $request->user(),
                subject: $rule,
                reason: $request->reason(),
                before: $before,
            );

            $rule->delete();
        });

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * The target agencies for a set of rules, keyed by ULID — one query for the
     * whole list rather than one per row. Suspended and soft-deleted agencies are
     * INCLUDED (`withTrashed`) on purpose: the list has to be able to say "this
     * rule points at a suspended agency", which it cannot do if the read hides
     * them.
     *
     * @param  Collection<int, AdminAutomationRule>  $rules
     * @return array<string, Agency>
     */
    private function resolveTargets(Collection $rules): array
    {
        $ulids = $rules
            ->map(static fn (AdminAutomationRule $rule): ?string => $rule->targetAgencyUlid())
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ulids === []) {
            return [];
        }

        return Agency::withTrashed()
            ->whereIn('ulid', $ulids)
            ->get()
            ->keyBy('ulid')
            ->all();
    }

    /**
     * The auditable shape of a rule. Includes the fire trace, because "how often
     * had it fired when this happened" is part of what a reader of the audit log
     * needs — especially on the delete row, where nothing else survives.
     *
     * @return array<string, mixed>
     */
    private function snapshot(AdminAutomationRule $rule): array
    {
        return [
            'ulid' => $rule->ulid,
            'name' => $rule->name,
            'event' => $rule->event->value,
            'action' => $rule->action->value,
            'config' => $rule->config,
            'enabled' => $rule->enabled,
            'fire_count' => $rule->fire_count,
            'last_fired_at' => $rule->last_fired_at?->toIso8601String(),
        ];
    }

    private function authorizePlatformAdmin(Request $request): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }
        if ($user->type !== UserType::PlatformAdmin) {
            abort(Response::HTTP_FORBIDDEN);
        }
    }
}
