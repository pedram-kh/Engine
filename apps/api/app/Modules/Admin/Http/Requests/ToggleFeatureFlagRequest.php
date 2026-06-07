<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a feature-flag toggle (Sprint 13, D-6).
 *
 * Every flip — activate OR deactivate — carries a MANDATORY reason: the
 * `feature_flag.toggled` verb requiresReason(), and the reason is the only
 * record of WHY a platform capability was turned on/off (the audit row is
 * the single source of truth — Pennant's `features` table holds only the
 * resulting value, never the intent). Authorization is the controller's
 * platform_admin gate; this request only shapes the payload.
 */
final class ToggleFeatureFlagRequest extends FormRequest
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
