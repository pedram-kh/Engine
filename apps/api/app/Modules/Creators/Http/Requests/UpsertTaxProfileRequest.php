<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Requests;

use App\Modules\Creators\Enums\TaxFormType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for PATCH /api/v1/creators/me/wizard/tax.
 *
 * tax_form_type values are the canonical four from
 * 20-PHASE-1-SPEC.md § 6.1 Step 6 (Pedram-confirmed open item 4):
 * eu_self_employed, eu_company, uk_self_employed, uk_company.
 *
 * Tax-id and legal-name are encrypted at rest via the model's casts;
 * the validation layer only enforces presence + length.
 */
final class UpsertTaxProfileRequest extends FormRequest
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
            'tax_form_type' => ['required', Rule::enum(TaxFormType::class)],
            'legal_name' => ['required', 'string', 'max:255'],
            'tax_id' => ['required', 'string', 'max:64'],
            'address' => ['required', 'array'],
            'address.country_code' => ['required', 'string', 'size:2'],
            'address.city' => ['required', 'string', 'max:120'],
            'address.postal_code' => ['required', 'string', 'max:20'],
            'address.street' => ['required', 'string', 'max:255'],
        ];
    }
}
