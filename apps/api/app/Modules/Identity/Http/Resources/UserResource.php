<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public projection of a {@see User}, shaped per docs/04-API-DESIGN.md §7.
 *
 * Sensitive columns (password, two_factor_*, remember_token) are
 * structurally absent — they are not in `$fillable` and not in this
 * resource. The model's `$hidden` is the secondary defense; the absence
 * of any code path that selects them is the primary defense.
 */
final class UserResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->ulid,
            'type' => 'user',
            'attributes' => [
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'name' => $user->name,
                'user_type' => $user->type->value,
                'preferred_language' => $user->preferred_language,
                'preferred_currency' => $user->preferred_currency,
                'timezone' => $user->timezone,
                'theme_preference' => $user->theme_preference->value,
                'mfa_required' => $user->mfa_required,
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'created_at' => $user->created_at->toIso8601String(),
            ],
        ];
    }
}
