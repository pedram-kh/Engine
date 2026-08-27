<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Admin\Enums\AutomationAction;
use App\Modules\Admin\Enums\AutomationEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new automation rule (AH-090, D6).
 *
 * `event` and `action` are validated against the ENUMS, so a payload naming a
 * capability this version does not have is refused at the boundary rather than
 * stored and then silently skipped forever by the job.
 *
 * The config is validated per-action: `connect_to_agency` needs an `agency_id`
 * that resolves to a live, non-suspended agency. A rule pointing at a suspended
 * agency is refusable at creation time even though the same rule can DRIFT into
 * that state later — which is why the job re-checks and the list renders the
 * broken state. Catching it here is the cheap half, not the whole guard.
 *
 * `reason` is mandatory: `automation_rule.created` requiresReason(), and
 * AuditLogger throws without one — so this rule is structural, not decorative.
 */
final class StoreAutomationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'event' => ['required', 'string', Rule::in(array_column(AutomationEvent::cases(), 'value'))],
            'action' => ['required', 'string', Rule::in(array_column(AutomationAction::cases(), 'value'))],
            'enabled' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],

            // Per-action config. Only `connect_to_agency` exists in v1; a second
            // action adds its own keys here and to
            // AutomationAction::requiredConfigKeys().
            'config' => ['required', 'array'],
            'config.agency_id' => [
                'required_if:action,'.AutomationAction::ConnectToAgency->value,
                'string',
                Rule::exists('agencies', 'ulid')->whereNull('deleted_at')->whereNull('suspended_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'config.agency_id.exists' => 'The selected agency does not exist or is suspended.',
        ];
    }

    public function ruleName(): string
    {
        return trim((string) $this->input('name'));
    }

    public function event(): AutomationEvent
    {
        return AutomationEvent::from((string) $this->input('event'));
    }

    public function action(): AutomationAction
    {
        return AutomationAction::from((string) $this->input('action'));
    }

    public function enabled(): bool
    {
        return $this->boolean('enabled', true);
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        /** @var array<string, mixed> $config */
        $config = (array) $this->input('config', []);

        // Only the keys this action declares — a payload carrying extra keys
        // cannot smuggle them into a JSON column no validator reads again.
        return array_intersect_key(
            $config,
            array_flip($this->action()->requiredConfigKeys()),
        );
    }

    public function reason(): string
    {
        return trim((string) $this->input('reason'));
    }
}
