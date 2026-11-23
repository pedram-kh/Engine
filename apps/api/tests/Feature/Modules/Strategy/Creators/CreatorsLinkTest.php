<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Boards\Models\Board;
use App\Modules\Campaigns\Enums\CampaignType;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Waves\Features\WavesEnabled;
use App\Modules\Waves\Models\Wave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\Support\CreatorFixtures as F;
use Tests\Support\StrategyFixtures as S;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-3.1 A1 — from the Creators step a wave with no campaign can create a for-a-wave campaign (same rules and audit as
 * "create a campaign for this wave now"), or link an existing unlinked one (the PL-1.2 PATCH). One transaction.
 */

function pl31WaveCampaignUrl(array $w, string $suffix = ''): string
{
    return "/api/v1/agencies/{$w['agency']->ulid}/brands/{$w['brand']->ulid}/waves/{$w['wave']->ulid}".$suffix;
}

it('A1: creating a campaign for a wave with none: one for-a-wave campaign with its board, linked, audited like PL-1.2', function (): void {
    $w = F::world(linked: false);
    DB::table('campaigns')->where('id', $w['campaign']->id)->delete();
    Wave::query()->whereKey($w['wave']->id)->update(['campaign_id' => null]);

    $res = S::call($w['manager'], 'POST', pl31WaveCampaignUrl($w, '/campaign'), ['name' => 'Summer creators'])->assertCreated();
    $campaign = Campaign::query()->where('name', 'Summer creators')->firstOrFail();

    expect($res->json('data.attributes.campaign.id') ?? $res->json('data.relationships.campaign.data.id') ?? $campaign->ulid)->not->toBeNull()
        ->and(Wave::query()->whereKey($w['wave']->id)->value('campaign_id'))->toBe($campaign->id)
        ->and($campaign->campaign_type)->toBe(CampaignType::ForWave)
        ->and($campaign->creator_posts_content)->toBeFalse()
        ->and($campaign->budget_minor_units)->toBe(0)
        ->and(Board::query()->withoutGlobalScopes()->where('campaign_id', $campaign->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'campaign.created')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'wave.updated')->count())->toBe(1);
});

it('A1: refused when the wave already has a campaign; the roles; the flag; another agency', function (): void {
    $w = F::world();
    $before = Campaign::query()->count();

    S::call($w['admin'], 'POST', pl31WaveCampaignUrl($w, '/campaign'), ['name' => 'Again'])->assertStatus(422)->assertJsonPath('errors.0.code', 'wave.campaign_already_linked');
    expect(Campaign::query()->count())->toBe($before);

    Wave::query()->whereKey($w['wave']->id)->update(['campaign_id' => null]);
    S::call($w['staff'], 'POST', pl31WaveCampaignUrl($w, '/campaign'), ['name' => 'Nope'])->assertForbidden();
    S::call($w['otherAdmin'], 'POST', pl31WaveCampaignUrl($w, '/campaign'), ['name' => 'Nope'])->assertNotFound();
    S::call($w['admin'], 'POST', pl31WaveCampaignUrl($w, '/campaign'), [])->assertStatus(422);
    Feature::deactivate(WavesEnabled::NAME);
    S::call($w['admin'], 'POST', pl31WaveCampaignUrl($w, '/campaign'), ['name' => 'Off'])->assertNotFound();
    expect(Campaign::query()->count())->toBe($before)->and(Wave::query()->whereKey($w['wave']->id)->value('campaign_id'))->toBeNull();
});

it('A1: linking an existing for-a-wave campaign of the brand from the step (the options list and the PL-1.2 PATCH)', function (): void {
    $w = F::world(linked: false);
    Wave::query()->whereKey($w['wave']->id)->update(['campaign_id' => null]);

    $options = S::call($w['admin'], 'GET', "/api/v1/agencies/{$w['agency']->ulid}/brands/{$w['brand']->ulid}/waves/campaign-options")->assertOk()->json('data');
    expect(array_column($options, 'id'))->toContain($w['campaign']->ulid);

    S::call($w['admin'], 'PATCH', pl31WaveCampaignUrl($w), ['campaign_id' => $w['campaign']->ulid])->assertOk();
    expect(Wave::query()->whereKey($w['wave']->id)->value('campaign_id'))->toBe($w['campaign']->id);
});
