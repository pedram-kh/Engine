<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 4 Chunk 3 — Cluster 5: surface the rejection feedback to the
 * creator (D-c3-1). Today rejection_reason / rejected_at live only in the
 * admin_attributes block (null on /creators/me); this exposes them on the
 * creator-facing attributes so the dashboard rejected-banner can render
 * the reason as editing guidance.
 */
it('exposes rejection_reason + rejected_at on /creators/me for a rejected creator (break-revert)', function (): void {
    $user = User::factory()->create();
    CreatorFactory::new()->createOne([
        'user_id' => $user->id,
        'application_status' => ApplicationStatus::Rejected->value,
        'rejected_at' => now()->subDay(),
        'rejection_reason' => 'Portfolio insufficient for Tier 1 review.',
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/creators/me');

    $response->assertOk();
    // Break-revert: withhold the field from the creator-facing attributes
    // → this assertion fails (the creator can no longer see the reason).
    expect($response->json('data.attributes.rejection_reason'))
        ->toBe('Portfolio insufficient for Tier 1 review.');
    expect($response->json('data.attributes.rejected_at'))->not->toBeNull();
});

it('returns null rejection fields on /creators/me for a non-rejected creator', function (): void {
    $user = User::factory()->create();
    CreatorFactory::new()->createOne([
        'user_id' => $user->id,
        'application_status' => ApplicationStatus::Pending->value,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/creators/me');

    $response->assertOk();
    expect($response->json('data.attributes.rejection_reason'))->toBeNull();
    expect($response->json('data.attributes.rejected_at'))->toBeNull();
});
