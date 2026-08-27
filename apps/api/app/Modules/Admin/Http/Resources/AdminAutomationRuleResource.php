<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Resources;

use App\Modules\Admin\Models\AdminAutomationRule;
use App\Modules\Agencies\Models\Agency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One automation rule for the admin panel (AH-090, D6).
 *
 * @property-read AdminAutomationRule $resource
 */
final class AdminAutomationRuleResource extends JsonResource
{
    /**
     * @param  array<string, Agency>  $agenciesByUlid  resolved targets, keyed by
     *                                                 ULID — passed in by the
     *                                                 controller so a list of N
     *                                                 rules costs one agency
     *                                                 query, not N
     */
    public function __construct(
        AdminAutomationRule $resource,
        private readonly array $agenciesByUlid = [],
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $rule = $this->resource;
        $targetUlid = $rule->targetAgencyUlid();
        $agency = $targetUlid === null ? null : ($this->agenciesByUlid[$targetUlid] ?? null);

        return [
            'id' => $rule->ulid,
            'type' => 'automation_rules',
            'attributes' => [
                'name' => $rule->name,
                'event' => $rule->event->value,
                'action' => $rule->action->value,
                'enabled' => $rule->enabled,
                'target_agency_id' => $targetUlid,
                'target_agency_name' => $agency?->name,
                /*
                 * The rule's health, computed rather than stored. `config` holds
                 * a ULID with no FK behind it (AH-090, C10), so a target can be
                 * deleted or suspended out from under a rule at any time. The
                 * job already refuses to fire in those states; this is what
                 * makes the SAME fact visible in the UI instead of leaving an
                 * operator staring at a rule that silently never fires.
                 */
                'target_agency_suspended' => $agency?->isSuspended() ?? false,
                'broken_reason' => $this->brokenReason($targetUlid, $agency),
                'last_fired_at' => $rule->last_fired_at?->toIso8601String(),
                'fire_count' => $rule->fire_count,
                'created_at' => $rule->created_at->toIso8601String(),
                'updated_at' => $rule->updated_at->toIso8601String(),
            ],
        ];
    }

    /**
     * Null when the rule can act. Otherwise the reason it cannot, in the same
     * vocabulary the job's skip reasons use.
     */
    private function brokenReason(?string $targetUlid, ?Agency $agency): ?string
    {
        if ($targetUlid === null) {
            return 'misconfigured';
        }

        if ($agency === null) {
            return 'agency_missing';
        }

        return $agency->isSuspended() ? 'agency_suspended' : null;
    }
}
