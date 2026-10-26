<?php

declare(strict_types=1);

use App\Modules\Identity\Features\AccountDeletionEnabled;
use App\TestHelpers\Http\Controllers\SetFeatureFlagController;
use App\TestHelpers\Http\Middleware\VerifyTestHelperToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Feature-flag test-helper (AH-104d)
|--------------------------------------------------------------------------
|
| POST /_test/feature-flag/{name} turns an allow-listed Pennant flag ON for
| everyone, DELETE turns it OFF. The state is read back through Pennant itself
| (not through the endpoint's own echo), so a controller that answered "active"
| without storing it cannot agree with itself.
|
| The header-missing and wrong-token cases for `_test/*` are exercised once,
| centrally, in `GatingTest.php`.
*/

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withHeader(VerifyTestHelperToken::HEADER, (string) config('test_helpers.token'));
});

it('POST turns the flag on and DELETE turns it back off, as Pennant reads it', function (): void {
    expect(Feature::active(AccountDeletionEnabled::NAME))->toBeFalse();

    $this->postJson('/api/v1/_test/feature-flag/'.AccountDeletionEnabled::NAME)
        ->assertOk()
        ->assertJsonPath('data.name', AccountDeletionEnabled::NAME)
        ->assertJsonPath('data.active', true);

    expect(Feature::active(AccountDeletionEnabled::NAME))->toBeTrue();

    $this->deleteJson('/api/v1/_test/feature-flag/'.AccountDeletionEnabled::NAME)
        ->assertOk()
        ->assertJsonPath('data.active', false);

    expect(Feature::active(AccountDeletionEnabled::NAME))->toBeFalse();
});

it('is idempotent in both directions', function (): void {
    $this->postJson('/api/v1/_test/feature-flag/'.AccountDeletionEnabled::NAME)->assertOk();
    $this->postJson('/api/v1/_test/feature-flag/'.AccountDeletionEnabled::NAME)->assertOk();
    expect(Feature::active(AccountDeletionEnabled::NAME))->toBeTrue();

    $this->deleteJson('/api/v1/_test/feature-flag/'.AccountDeletionEnabled::NAME)->assertOk();
    $this->deleteJson('/api/v1/_test/feature-flag/'.AccountDeletionEnabled::NAME)->assertOk();
    expect(Feature::active(AccountDeletionEnabled::NAME))->toBeFalse();
});

it('refuses a flag that is not on the allow-list, on both verbs, and switches nothing', function (): void {
    $name = 'contract_signing_enabled';

    expect(SetFeatureFlagController::ALLOWED_NAMES)->not->toContain($name);

    $post = $this->postJson('/api/v1/_test/feature-flag/'.$name);
    $post->assertStatus(422);
    expect($post->json('error'))->toContain('unknown feature flag');

    $this->deleteJson('/api/v1/_test/feature-flag/'.$name)->assertStatus(422);

    expect(Feature::active($name))->toBeFalse();
    expect(Feature::active(AccountDeletionEnabled::NAME))->toBeFalse();
});

it('allows exactly the flag this chunk needs (a new entry is a reviewed change)', function (): void {
    expect(SetFeatureFlagController::ALLOWED_NAMES)->toBe([AccountDeletionEnabled::NAME]);
});

it('is not reachable when the gate is closed', function (): void {
    config()->set('test_helpers.token', '');

    $this->postJson('/api/v1/_test/feature-flag/'.AccountDeletionEnabled::NAME)->assertNotFound();
    expect(Feature::active(AccountDeletionEnabled::NAME))->toBeFalse();
});
