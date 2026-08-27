<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an automation-rule enable/disable flip (AH-090, D6).
 *
 * The ToggleFeatureFlagRequest shape, for the same reason: both directions carry
 * a MANDATORY reason. Arming a rule that will write relationship rows and
 * silently stopping one the business depends on are both changes to platform
 * behaviour, and `automation_rule.toggled` requiresReason() in both directions.
 */
final class ToggleAutomationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function enabled(): bool
    {
        return $this->boolean('enabled');
    }

    public function reason(): string
    {
        return trim((string) $this->input('reason'));
    }
}
