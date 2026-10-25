<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Erasure\Features\AccountErasureExecuteEnabled;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => Feature::flushCache());

/*
|--------------------------------------------------------------------------
| AH-104c C3-1 — the flag in front of the Erase button
|--------------------------------------------------------------------------
*/

it('is defined, named as documented, and OFF by default', function (): void {
    expect(AccountErasureExecuteEnabled::NAME)->toBe('account_erasure_execute_enabled')
        ->and(Feature::defined())->toContain(AccountErasureExecuteEnabled::NAME)
        ->and(Feature::active(AccountErasureExecuteEnabled::NAME))->toBeFalse();

    Feature::activate(AccountErasureExecuteEnabled::NAME);
    expect(Feature::active(AccountErasureExecuteEnabled::NAME))->toBeTrue();

    Feature::deactivate(AccountErasureExecuteEnabled::NAME);
    expect(Feature::active(AccountErasureExecuteEnabled::NAME))->toBeFalse();
});

it('is on the admin toggle list with a description that says what off means, and flipping it writes the audit row with the reason', function (): void {
    $admin = User::factory()->platformAdmin()->createOne(['two_factor_confirmed_at' => now()]);

    $listed = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/feature-flags')->collect('data')
        ->firstWhere('id', AccountErasureExecuteEnabled::NAME);

    expect($listed)->not->toBeNull()
        ->and($listed['attributes']['enabled'])->toBeFalse()
        ->and($listed['attributes']['description'])->toContain('Off: the Erase button and its endpoint answer 409')
        ->and($listed['attributes']['description'])->toContain('the queue, the hold, the plan view and the command are unaffected');

    $this->actingAs($admin, 'web_admin')
        ->postJson('/api/v1/admin/feature-flags/'.AccountErasureExecuteEnabled::NAME, ['enabled' => true, 'reason' => 'C4: first real erasure, one named account'])
        ->assertOk();

    $row = AuditLog::query()->where('action', AuditAction::FeatureFlagToggled)->latest('id')->firstOrFail();
    expect(Feature::active(AccountErasureExecuteEnabled::NAME))->toBeTrue()
        ->and($row->actor_id)->toBe($admin->id)
        ->and($row->reason)->toBe('C4: first real erasure, one named account');
});

it('refuses to flip it with no reason', function (): void {
    $admin = User::factory()->platformAdmin()->createOne(['two_factor_confirmed_at' => now()]);

    $this->actingAs($admin, 'web_admin')
        ->postJson('/api/v1/admin/feature-flags/'.AccountErasureExecuteEnabled::NAME, ['enabled' => true])
        ->assertStatus(422);

    expect(Feature::active(AccountErasureExecuteEnabled::NAME))->toBeFalse();
});
