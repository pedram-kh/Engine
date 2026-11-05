<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The agency edits an assignment's offer AMOUNT (AH-118 item 2, Q12).
 *
 * Amount only: the currency is the campaign's and `fee_per` is unchanged, so a
 * client that sends `agreed_fee_currency` is told it is not accepted rather than
 * having it silently ignored.
 */
final class EditAssignmentOfferRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'agreed_fee_minor_units' => ['required', 'integer', 'min:1'],
            'agreed_fee_currency' => ['prohibited'],
        ];
    }
}
