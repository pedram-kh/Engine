<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an automation-rule deletion (AH-090, D7).
 *
 * The delete is HARD — no soft-delete column. Reasoning: a soft-deleted rule
 * would need a `whereNull('deleted_at')` filter in the dispatch query, a new
 * silent-failure surface (miss it and deleted rules keep firing) bought for no
 * gain, since nothing in the product reads a dead rule.
 *
 * What is preserved instead is the record: `automation_rule.deleted` captures the
 * full row — config, fire count, last fired — in the audit `before` snapshot, so
 * "what did that rule do before someone removed it" stays answerable. Hence the
 * MANDATORY reason: once the row is gone, this text and that snapshot are all
 * that is left.
 *
 * Deletion stops FUTURE fires and never unwinds past ones (D7): relations a rule
 * already created are real relationships, not automation state.
 */
final class DeleteAutomationRuleRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->input('reason'));
    }
}
