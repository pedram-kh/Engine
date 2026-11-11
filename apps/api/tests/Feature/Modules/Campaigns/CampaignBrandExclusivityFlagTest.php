<?php

declare(strict_types=1);

use App\Modules\Creators\Features\CampaignBrandExclusivityEnabled;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * AH-120 A1 — the flag: OFF by default, in the admin registry, and carried on
 * `/me` `features` for agency users only (a UI gate; the API enforces the flag
 * itself).
 */

it('defines the flag OFF by default under its ruled name', function (): void {
    expect(Feature::active(CampaignBrandExclusivityEnabled::NAME))->toBeFalse()
        ->and(CampaignBrandExclusivityEnabled::NAME)->toBe('campaign_brand_exclusivity_enabled');
});

it('lists the flag in the admin registry and lets an admin flip it', function (): void {
    $admin = User::factory()->create(['type' => UserType::PlatformAdmin, 'two_factor_confirmed_at' => now()]);

    /** @var list<array{attributes: array{name: string}}> $rows */
    $rows = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/feature-flags')->json('data');
    $names = array_map(static fn (array $row): string => $row['attributes']['name'], $rows);
    expect($names)->toContain(CampaignBrandExclusivityEnabled::NAME);

    $this->actingAs($admin, 'web_admin')->postJson(
        '/api/v1/admin/feature-flags/'.CampaignBrandExclusivityEnabled::NAME,
        ['enabled' => true, 'reason' => 'Walk of brand exclusivity.'],
    )->assertOk();
    expect(Feature::active(CampaignBrandExclusivityEnabled::NAME))->toBeTrue();
});

it('carries the flag on /me for an agency user, following the flag', function (): void {
    $user = User::factory()->agencyAdmin()->createOne();

    $this->actingAs($user, 'web')->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.attributes.features.campaign_brand_exclusivity', false);

    Feature::activate(CampaignBrandExclusivityEnabled::NAME);

    $this->actingAs($user, 'web')->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.attributes.features.campaign_brand_exclusivity', true);
});

it('is false for a creator and a platform admin even with the flag on', function (): void {
    Feature::activate(CampaignBrandExclusivityEnabled::NAME);

    foreach ([User::factory()->creator()->createOne(), User::factory()->platformAdmin()->createOne()] as $user) {
        $this->actingAs($user, 'web')->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.attributes.features.campaign_brand_exclusivity', false);
    }
});
