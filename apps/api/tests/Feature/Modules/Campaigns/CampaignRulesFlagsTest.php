<?php

declare(strict_types=1);

use App\Modules\Creators\Features\AssignmentOfferEditEnabled;
use App\Modules\Creators\Features\CampaignRegionRestrictionEnabled;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * AH-118 A2 — the two flags: OFF by default, in the admin registry, and carried
 * on `/me` `features` for agency users only (a UI gate; the API enforces each
 * flag itself).
 */

it('defines both flags OFF by default', function (): void {
    expect(Feature::active(CampaignRegionRestrictionEnabled::NAME))->toBeFalse()
        ->and(Feature::active(AssignmentOfferEditEnabled::NAME))->toBeFalse()
        ->and(CampaignRegionRestrictionEnabled::NAME)->toBe('campaign_region_restriction_enabled')
        ->and(AssignmentOfferEditEnabled::NAME)->toBe('assignment_offer_edit_enabled');
});

it('lists both flags in the admin registry and lets an admin flip them', function (): void {
    $admin = User::factory()->create(['type' => UserType::PlatformAdmin, 'two_factor_confirmed_at' => now()]);

    /** @var list<array{attributes: array{name: string}}> $rows */
    $rows = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/feature-flags')->json('data');
    $names = array_map(static fn (array $row): string => $row['attributes']['name'], $rows);
    expect($names)->toContain(CampaignRegionRestrictionEnabled::NAME)
        ->and($names)->toContain(AssignmentOfferEditEnabled::NAME);

    $this->actingAs($admin, 'web_admin')->postJson(
        '/api/v1/admin/feature-flags/'.AssignmentOfferEditEnabled::NAME,
        ['enabled' => true, 'reason' => 'Walk of the offer edit.'],
    )->assertOk();
    expect(Feature::active(AssignmentOfferEditEnabled::NAME))->toBeTrue();
});

it('carries both flags on /me for an agency user, following each flag', function (): void {
    $user = User::factory()->agencyAdmin()->createOne();

    $this->actingAs($user, 'web')->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.attributes.features.campaign_region_restriction', false)
        ->assertJsonPath('data.attributes.features.assignment_offer_edit', false);

    Feature::activate(CampaignRegionRestrictionEnabled::NAME);
    Feature::activate(AssignmentOfferEditEnabled::NAME);

    $this->actingAs($user, 'web')->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.attributes.features.campaign_region_restriction', true)
        ->assertJsonPath('data.attributes.features.assignment_offer_edit', true);
});

it('is false for a creator and a platform admin even with both flags on', function (): void {
    Feature::activate(CampaignRegionRestrictionEnabled::NAME);
    Feature::activate(AssignmentOfferEditEnabled::NAME);

    foreach ([User::factory()->creator()->createOne(), User::factory()->platformAdmin()->createOne()] as $user) {
        $this->actingAs($user, 'web')->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.attributes.features.campaign_region_restriction', false)
            ->assertJsonPath('data.attributes.features.assignment_offer_edit', false);
    }
});
