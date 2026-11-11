<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Identity\Models\User;
use App\TestHelpers\Http\Middleware\VerifyTestHelperToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** AH-120 A7 — the E2E helpers' exclusivity options (default behaviour unchanged). */
uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withHeader(VerifyTestHelperToken::HEADER, (string) config('test_helpers.token'));
    $user = User::factory()->create(['email' => 'excl-creator@example.com']);
    CreatorFactory::new()->createOne(['user_id' => $user->id, 'application_status' => ApplicationStatus::Incomplete]);
});

function contractedUrl(Agency $agency): string
{
    return "/api/v1/_test/agencies/{$agency->ulid}/contracted-assignment";
}

it('returns the brand ULID from the listed-job helper', function (): void {
    $r = $this->postJson('/api/v1/_test/creators/listed-job', ['email' => 'excl-creator@example.com'])->assertStatus(201);

    $brand = Brand::query()->withoutGlobalScopes()->where('ulid', $r->json('data.brand_ulid'))->firstOrFail();
    expect($brand->name)->toBe($r->json('data.brand_name'));
});

it('leaves the contracted-assignment campaign without exclusivity when no option is sent', function (): void {
    $agency = Agency::factory()->createOne();

    $r = $this->postJson(contractedUrl($agency), ['email' => 'excl-creator@example.com'])->assertStatus(201);

    $campaign = Campaign::query()->withoutGlobalScopes()->where('ulid', $r->json('data.campaign_ulid'))->firstOrFail();
    expect($campaign->exclusiveBrands()->count())->toBe(0)->and($campaign->exclusivity_ends_on)->toBeNull();
});

it('blocks the named brands and sets the end date on the campaign it creates', function (): void {
    $listed = $this->postJson('/api/v1/_test/creators/listed-job', ['email' => 'excl-creator@example.com'])->assertStatus(201);
    $agency = Agency::query()->where('ulid', $listed->json('data.agency_ulid'))->firstOrFail();

    $r = $this->postJson(contractedUrl($agency), [
        'email' => 'excl-creator@example.com',
        'exclusive_brand_ids' => [$listed->json('data.brand_ulid')],
        'exclusivity_ends_on' => '2030-01-31',
    ])->assertStatus(201);

    $campaign = Campaign::query()->withoutGlobalScopes()->where('ulid', $r->json('data.campaign_ulid'))->firstOrFail();
    expect($campaign->exclusiveBrands()->pluck('brands.ulid')->all())->toBe([$listed->json('data.brand_ulid')])
        ->and($campaign->exclusivity_ends_on?->toDateString())->toBe('2030-01-31');
});

it('refuses a brand that is not this agency\'s, and a malformed date', function (): void {
    $listed = $this->postJson('/api/v1/_test/creators/listed-job', ['email' => 'excl-creator@example.com'])->assertStatus(201);
    $other = Agency::factory()->createOne();

    $this->postJson(contractedUrl($other), [
        'email' => 'excl-creator@example.com',
        'exclusive_brand_ids' => [$listed->json('data.brand_ulid')],
    ])->assertStatus(422);

    $this->postJson(contractedUrl($other), ['email' => 'excl-creator@example.com', 'exclusivity_ends_on' => 'soon'])->assertStatus(422);
});
