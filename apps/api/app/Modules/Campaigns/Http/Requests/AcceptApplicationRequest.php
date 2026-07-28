<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Requests;

use App\Modules\Campaigns\Http\Requests\Concerns\ValidatesAssignmentOffer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates ACCEPTING a job application (AH-058, D2) — which is to say, it
 * validates an OFFER.
 *
 * The payload is {@see InviteAssignmentRequest}'s minus `creator_id`: the
 * applicant is the application's own creator, and accepting a client-supplied
 * creator id here would be an invitation to accept one application on another
 * creator's behalf. The rules themselves are shared through
 * {@see ValidatesAssignmentOffer} so the two paths cannot drift.
 *
 * Role authorization (the `invite` execute ability) lives in the controller via
 * Gate::authorize, the house pattern.
 */
final class AcceptApplicationRequest extends FormRequest
{
    use ValidatesAssignmentOffer;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->assignmentOfferRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateOfferCurrencyAgainstCampaign($validator);
        });
    }
}
