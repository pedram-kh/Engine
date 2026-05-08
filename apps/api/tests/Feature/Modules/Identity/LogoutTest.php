<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Events\UserLoggedOut;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('returns 204 and invalidates the session on logout', function (): void {
    $user = User::factory()->createOne();

    $this->actingAs($user, 'web');

    Event::fake([UserLoggedOut::class]);

    $this->postJson('/api/v1/auth/logout')->assertStatus(204);

    Event::assertDispatched(UserLoggedOut::class, function (UserLoggedOut $event) use ($user): bool {
        return $event->user->is($user) && $event->guard === 'web';
    });
});

it('writes auth.logout audit row', function (): void {
    $user = User::factory()->createOne();
    $this->actingAs($user, 'web')->postJson('/api/v1/auth/logout')->assertStatus(204);

    $audit = AuditLog::query()->where('action', AuditAction::AuthLogout->value)->latest('id')->firstOrFail();
    expect($audit->actor_id)->toBe($user->id)
        ->and($audit->subject_id)->toBe($user->id);
});

it('rejects logout when not authenticated', function (): void {
    $this->postJson('/api/v1/auth/logout')->assertStatus(401);
});

it('admin logout uses the web_admin guard', function (): void {
    $admin = User::factory()->platformAdmin()->createOne();
    $this->actingAs($admin, 'web_admin');

    Event::fake([UserLoggedOut::class]);

    $this->postJson('/api/v1/admin/auth/logout')->assertStatus(204);

    Event::assertDispatched(UserLoggedOut::class, function (UserLoggedOut $event): bool {
        return $event->guard === 'web_admin';
    });
});
