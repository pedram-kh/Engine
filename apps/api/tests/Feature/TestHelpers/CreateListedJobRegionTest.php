<?php

declare(strict_types=1);

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Identity\Models\User;
use App\TestHelpers\Http\Middleware\VerifyTestHelperToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** AH-118 — the listed-job helper's region options (default behaviour unchanged). */
uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withHeader(VerifyTestHelperToken::HEADER, (string) config('test_helpers.token'));
});

function listedJobCreator(): mixed
{
    $user = User::factory()->create(['email' => 'region-creator@example.com']);

    return CreatorFactory::new()->createOne([
        'user_id' => $user->id,
        'application_status' => ApplicationStatus::Incomplete,
    ]);
}

it('leaves the campaign unrestricted with the default regions when no region option is sent', function (): void {
    listedJobCreator();

    $r = $this->postJson('/api/v1/_test/creators/listed-job', ['email' => 'region-creator@example.com'])
        ->assertStatus(201);

    $campaign = Campaign::query()->withoutGlobalScopes()->where('ulid', $r->json('data.campaign_ulid'))->firstOrFail();
    expect($campaign->restrict_to_listing_regions)->toBeFalse()
        ->and($campaign->listing_regions)->toBe(['PT', 'ES']);
});

it('sets the switch, the regions and the creator country when asked', function (): void {
    $creator = listedJobCreator();

    $r = $this->postJson('/api/v1/_test/creators/listed-job', [
        'email' => 'region-creator@example.com',
        'restrict_to_listing_regions' => true,
        'listing_regions' => ['FR', 'DE'],
        'creator_country' => 'PT',
    ])->assertStatus(201);

    $campaign = Campaign::query()->withoutGlobalScopes()->where('ulid', $r->json('data.campaign_ulid'))->firstOrFail();
    expect($campaign->restrict_to_listing_regions)->toBeTrue()
        ->and($campaign->listing_regions)->toBe(['FR', 'DE'])
        ->and($creator->fresh()->country_code)->toBe('PT');
});

it('refuses a country that is not two letters', function (): void {
    listedJobCreator();

    $this->postJson('/api/v1/_test/creators/listed-job', [
        'email' => 'region-creator@example.com',
        'creator_country' => 'Portugal',
    ])->assertStatus(422);
});
