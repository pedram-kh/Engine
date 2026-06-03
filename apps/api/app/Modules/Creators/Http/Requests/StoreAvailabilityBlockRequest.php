<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Requests;

use App\Modules\Creators\Enums\BlockType;
use App\Modules\Creators\Enums\Kind;
use App\Modules\Creators\Rules\WeeklyRecurrenceRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /api/v1/creators/me/availability (Sprint 5 Chunk A).
 *
 * Ownership is structural (the controller resolves the block from
 * $request->user()->creator), so authorize() is a pass-through.
 *
 * Notable rules:
 *   - ends_at strictly after starts_at (tz-aware: `date` accepts ISO 8601
 *     with offset, parsed via Carbon).
 *   - block_type ∈ BlockType enum.
 *   - kind ∈ Kind enum MINUS assignment_auto — a creator can never mint an
 *     "assignment auto" block; that kind is reserved for the deferred
 *     Sprint 8 auto-block-on-acceptance flow (D-a2).
 *   - recurrence_rule, when present, must be weekly-only (D-a3,
 *     {@see WeeklyRecurrenceRule}); it is required iff is_recurring is true.
 */
class StoreAvailabilityBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'is_all_day' => ['sometimes', 'boolean'],
            'block_type' => ['required', Rule::enum(BlockType::class)],
            'kind' => ['required', Rule::enum(Kind::class)->except([Kind::AssignmentAuto])],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_recurring' => ['sometimes', 'boolean'],
            'recurrence_rule' => ['sometimes', 'nullable', 'string', 'max:255', new WeeklyRecurrenceRule],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // A recurring block needs a rule, and a rule only means something on
        // a recurring block. Enforced here (rather than required_if) so the
        // boolean coercion of `is_recurring` (true/false/1/0/"1") is robust.
        $validator->after(function (Validator $validator): void {
            $isRecurring = $this->boolean('is_recurring');
            $hasRule = ! in_array($this->input('recurrence_rule'), [null, ''], true);

            if ($isRecurring && ! $hasRule) {
                $validator->errors()->add('recurrence_rule', 'A recurrence rule is required for a recurring block.');
            }

            if (! $isRecurring && $hasRule) {
                $validator->errors()->add('recurrence_rule', 'A recurrence rule may only be set when is_recurring is true.');
            }
        });
    }
}
