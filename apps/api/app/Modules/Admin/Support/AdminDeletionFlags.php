<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;

/**
 * The two facts an ADMIN screen needs about an account that is leaving (AH-104c C2-4), and the
 * only place they are spelled.
 *
 *   is_erased         the account has been erased: its name, email and avatar are gone from the
 *                     row (the email is an undeliverable placeholder), so the SPA must say
 *                     "Deleted creator" and never show the placeholder.
 *   deletion_pending  the person has asked to delete the account and the request stands (not
 *                     erased yet). A chip, so a reviewer does not approve, message or impersonate
 *                     someone who is leaving without knowing.
 *
 * ADMIN ONLY. `deletion_pending` is NEVER sent to an agency: a request that is later cancelled
 * must leave no trace there (AH-104b-del), so agency payloads carry `is_erased` alone (see
 * `EmitsErasedState`). An architecture spec fails when any agency module names this class or the
 * key.
 */
final class AdminDeletionFlags
{
    /**
     * @return array{is_erased: bool, deletion_pending: bool}
     */
    public static function forCreator(Creator $creator): array
    {
        $erased = $creator->isErased();

        return [
            'is_erased' => $erased,
            'deletion_pending' => ! $erased && $creator->user?->isDeletionPending() === true,
        ];
    }

    /**
     * @return array{is_erased: bool, deletion_pending: bool}
     */
    public static function forUser(User $user): array
    {
        return [
            'is_erased' => $user->isErased(),
            'deletion_pending' => $user->isDeletionPending(),
        ];
    }
}
