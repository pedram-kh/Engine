<?php

declare(strict_types=1);

use App\Modules\Notifications\Http\Controllers\NotificationController;
use App\Modules\Notifications\Http\Controllers\NotificationPreferenceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Notifications module routes
|--------------------------------------------------------------------------
|
| Mounted by NotificationsServiceProvider under the 'api' middleware group
| with prefix '/api/v1'. The per-user feed lives at `/me/notifications`,
| mirroring the `/me` surface (Identity module).
|
| Middleware (S11.0 Chunk 1, D-8/D-9): `auth:web` + `tenancy.set`, the same
| stack as `GET /me`. Notifications are user-level, ABOVE tenancy — the query
| is pure user-scope (the model omits BelongsToAgency), so `tenancy.set` is a
| documented no-op for it and is mounted only for parity with the rest of the
| `/me` surface. The fail-closed `tenancy` alias is intentionally NOT applied:
| creators and platform admins have no agency context and would 500.
|
| Per-user isolation is enforced in the controller (recipient_user_id = auth
| user), and these routes are on the cross-tenant allowlist in
| docs/security/tenancy.md §4.
|
*/

Route::prefix('me/notifications')
    ->middleware(['auth:web', 'tenancy.set'])
    ->name('me.notifications.')
    ->group(function (): void {
        Route::get('', [NotificationController::class, 'index'])->name('index');
        Route::get('unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
        Route::post('read-all', [NotificationController::class, 'readAll'])->name('read-all');
        Route::patch('{notification}/read', [NotificationController::class, 'markRead'])->name('read');
    });

// Per-user notification preferences (S11.0 Chunk 3b). The product's first user
// self-WRITE surface — same `auth:web` + `tenancy.set` stack as the feed, owner
// resolved from $request->user() (no path id, no policy). On the cross-tenant
// allowlist in docs/security/tenancy.md §4: prefs are user-global.
Route::prefix('me/notification-preferences')
    ->middleware(['auth:web', 'tenancy.set'])
    ->name('me.notification-preferences.')
    ->group(function (): void {
        Route::get('', [NotificationPreferenceController::class, 'index'])->name('index');
        Route::patch('', [NotificationPreferenceController::class, 'update'])->name('update');
    });
