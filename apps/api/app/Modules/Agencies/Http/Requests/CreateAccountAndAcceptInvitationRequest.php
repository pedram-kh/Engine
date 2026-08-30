<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Requests;

use App\Modules\Identity\Rules\PasswordIsNotBreached;
use App\Modules\Identity\Rules\StrongPassword;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for `POST /api/v1/agencies/{agency}/invitations/create-account`
 * (AH-095) — the stranger path: an invite-acceptance that also creates the
 * account.
 *
 * Deliberately has NO `email` field. Review priority #1
 * (email-immutability) is enforced by construction, not by a server-side
 * check on client input: the created account's email is ALWAYS
 * `$invitation->email`, because there is nowhere in this request for a
 * different email to travel. The email itself never round-trips from the
 * client on this endpoint — the SPA renders it read-only from the
 * (unauthenticated) preview response and never re-submits it.
 *
 * Password rules are the platform's standard set — the SAME
 * {@see StrongPassword} + {@see PasswordIsNotBreached} + `confirmed` combo
 * `SignUpRequest` and the password-reset flow use, so this surface lands
 * at exactly the same security floor (docs/05-SECURITY-COMPLIANCE.md §6.1).
 * No surface-specific weakening.
 */
final class CreateAccountAndAcceptInvitationRequest extends FormRequest
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
            'token' => ['required', 'string', 'size:64'],
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'last_name' => ['required', 'string', 'min:1', 'max:120'],
            'password' => [
                'required',
                'string',
                'confirmed',
                app(StrongPassword::class),
                app(PasswordIsNotBreached::class),
            ],
            'password_confirmation' => ['required', 'string'],
        ];
    }

    /**
     * @return array{name: string, last_name: string, password: string}
     */
    public function accountAttributes(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return [
            'name' => (string) $validated['name'],
            'last_name' => (string) $validated['last_name'],
            'password' => (string) $validated['password'],
        ];
    }

    /**
     * Normalise names BEFORE validation, mirroring `SignUpRequest`.
     */
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $lastName = $this->input('last_name');

        $this->merge([
            'name' => is_string($name) ? trim($name) : $name,
            'last_name' => is_string($lastName) ? trim($lastName) : $lastName,
        ]);
    }
}
