<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Admin\Enums\AutomationAction;
use App\Modules\Admin\Enums\AutomationEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an edit to an existing automation rule (AH-090, D6).
 *
 * Same shape as {@see StoreAutomationRuleRequest} — the edit dialog is the
 * create dialog with values in it, and a partial update that could leave a rule
 * with an `action` from one submission and a `config` from another is exactly
 * the drift the per-action config validation exists to prevent. So the payload
 * is complete, and `reason` is mandatory (`automation_rule.updated`
 * requiresReason()).
 */
final class UpdateAutomationRuleRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
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

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        /** @var array<string, mixed> $config */
        $config = (array) $this->input('config', []);

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
