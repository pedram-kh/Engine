<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\UpdateMeRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * PATCH /api/v1/me            (web guard, main SPA)
 * PATCH /api/v1/admin/me      (web_admin guard, admin SPA — gated by EnsureMfaForAdmins)
 *
 * Locale-only self-update. Persists the caller's `preferred_language` so a
 * chosen UI language survives reload and login (docs/00-MASTER-ARCHITECTURE
 * §13 — the persistence half of the locale chunk). The write is the
 * narrowest possible: {@see UpdateMeRequest} validates a single field, so
 * only `preferred_language` can ever change here — never name, email, or any
 * other profile attribute.
 *
 * Mirrors the GET {@see MeController} on middleware posture:
 *   - `auth:web` / `auth:web_admin` guarantees a non-null `$request->user()`
 *     (standard 401 envelope otherwise); the admin variant also mounts
 *     `EnsureMfaForAdmins`.
 *   - `tenancy.set` runs but is irrelevant: `preferred_language` lives on the
 *     global `users` row, NOT a tenant-scoped table, so the endpoint works
 *     for creators and platform admins who carry no agency context (the
 *     fail-closed `tenancy` alias is intentionally NOT applied). This is the
 *     "no-context" self-write — see docs/security/tenancy.md §4.
 *
 * No audit row: a UI-language preference is low-sensitivity, matching the
 * notification-preferences self-write precedent.
 */
final class UpdateMeController
{
    public function __invoke(UpdateMeRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        /** @var string $language */
        $language = $request->validated()['preferred_language'];

        $user->update(['preferred_language' => $language]);

        return UserResource::make($user)->response();
    }
}
