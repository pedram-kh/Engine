<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sprint 3 Chunk 2 sub-step 1 — wizard `verified` middleware gate
|--------------------------------------------------------------------------
|
| Defence-in-depth (#40) companion to the
| PasswordResetService::request() email_verified_at gate. The wizard
| route group at apps/api/app/Modules/Creators/Routes/api.php carries
| ['auth:web', 'tenancy.set', 'verified']; without `verified`, an
| authenticated-but-unverified User created via the bulk-invite
| throwaway-password path could reach the wizard and either advance
| their own application or interfere with the legitimate magic-link
| consumer. See docs/reviews/sprint-3-chunk-1-review.md "P1 blockers
| for Chunk 2".
|
| The break-revert verification supporting #40: the
| `it('rejects an authenticated-but-unverified user from every wizard
| route')` test below fails when `verified` is removed from the route
| group's middleware array — confirmed manually before this commit
| (sub-step 1 of Sprint 3 Chunk 2; see chunk-2 review file).
|
*/

it('rejects an authenticated-but-unverified user from every wizard route', function (): void {
    $user = User::factory()->unverified()->createOne();
    CreatorFactory::new()->bootstrap()->createOne(['user_id' => $user->id]);

    expect($user->email_verified_at)->toBeNull();

    // Cover one read endpoint + one mutation per HTTP verb the wizard
    // exposes. The middleware applies to the whole route group, so
    // exhaustive coverage isn't required — but covering each verb
    // pins that the framework's route-resolver carries the alias
    // through every method binding.
    $this->actingAs($user)
        ->getJson('/api/v1/creators/me')
        ->assertStatus(403);

    $this->actingAs($user)
        ->patchJson('/api/v1/creators/me/wizard/profile', [
            'display_name' => 'irrelevant',
            'country_code' => 'IT',
            'primary_language' => 'en',
            'categories' => ['lifestyle'],
        ])
        ->assertStatus(403);

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/wizard/social', [
            'platform' => 'instagram',
            'handle' => 'irrelevant',
            'profile_url' => 'https://instagram.com/irrelevant',
        ])
        ->assertStatus(403);

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/wizard/submit')
        ->assertStatus(403);
});

it('admits an authenticated and verified user to the wizard surface (regression guard for the verified gate)', function (): void {
    // Companion to the rejection test: pins that the verified
    // middleware was not accidentally over-applied (e.g., to a route
    // that should accept unverified callers). If this fails after
    // the rejection test passes, the gate is functionally an
    // unconditional 403 — which would silently break the wizard.
    $user = User::factory()->createOne();
    CreatorFactory::new()->bootstrap()->createOne(['user_id' => $user->id]);

    expect($user->email_verified_at)->not->toBeNull();

    $this->actingAs($user)
        ->getJson('/api/v1/creators/me')
        ->assertStatus(200);
});

it('source-inspection: every creators.me.* route declares the verified middleware', function (): void {
    // #1 source-inspection regression. If a future route is added to
    // the creators.me.* group without the `verified` middleware, this
    // test fails — the bulk-invite throwaway-password vector reopens
    // unless the new route deliberately excludes itself with a
    // documented reason (none in Sprint 3).
    $offenders = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();
        if (! str_starts_with($name, 'creators.me.')) {
            continue;
        }
        if (in_array('verified', $route->gatherMiddleware(), true)) {
            continue;
        }
        $offenders[] = $name.' ['.implode('|', $route->methods()).']';
    }

    expect($offenders)->toBe([], 'These creators.me.* routes are missing the verified middleware: '.implode(', ', $offenders));
});
