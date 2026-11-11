<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\JobsBoard\CreatorJobFixture;
use Tests\Fixtures\JobsBoard\ExclusivityFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-120 A6 — POST .../campaigns/{campaign}/exclusivity-conflicts: the invite
 * dialog's non-blocking warning. READ-ONLY despite the POST.
 */
beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function conflictsUrl(ExclusivityFixture $f): string
{
    return "/api/v1/agencies/{$f->job->agency->ulid}/campaigns/{$f->job->campaign->ulid}/exclusivity-conflicts";
}

function conflictsAdmin(ExclusivityFixture $f): User
{
    return User::factory()->agencyAdmin($f->job->agency)->createOne();
}

it('names the blocking campaign for a blocked creator, keyed by creator ULID, and omits the rest', function (): void {
    $f = ExclusivityFixture::make();
    $f->select(AssignmentStatus::Accepted);
    $other = CreatorJobFixture::make()->creator;

    $this->actingAs(conflictsAdmin($f))
        ->postJson(conflictsUrl($f), ['creator_ids' => [$f->job->creator->ulid, $other->ulid]])
        ->assertOk()
        ->assertExactJson(['data' => [
            $f->job->creator->ulid => ['blocking_campaign' => ['id' => $f->zavaCampaign->ulid, 'name' => ExclusivityFixture::ZAVA_CAMPAIGN_NAME]],
        ]]);
});

it('keys each blocked creator to their OWN entry when several are blocked', function (): void {
    $f = ExclusivityFixture::make();
    $f->select(AssignmentStatus::Accepted);

    $second = CreatorJobFixture::make()->creator;
    CampaignAssignment::factory()->createOne([
        'campaign_id' => $f->zavaCampaign->id,
        'creator_id' => $second->id,
        'status' => AssignmentStatus::Producing,
    ]);

    $data = $this->actingAs(conflictsAdmin($f))
        ->postJson(conflictsUrl($f), ['creator_ids' => [$second->ulid, $f->job->creator->ulid]])
        ->assertOk()->json('data');

    expect(array_keys($data))->toEqualCanonicalizing([$f->job->creator->ulid, $second->ulid])
        ->and($data[$second->ulid]['blocking_campaign']['id'])->toBe($f->zavaCampaign->ulid);
});

it('returns an empty data array when nothing blocks', function (): void {
    $f = ExclusivityFixture::make();

    $this->actingAs(conflictsAdmin($f))->postJson(conflictsUrl($f), ['creator_ids' => [$f->job->creator->ulid]])
        ->assertOk()->assertExactJson(['data' => []]);
});

it('returns an empty data array while the flag is OFF, whatever the data says', function (): void {
    $f = ExclusivityFixture::make(flag: false);
    $f->select(AssignmentStatus::Accepted);

    $this->actingAs(conflictsAdmin($f))->postJson(conflictsUrl($f), ['creator_ids' => [$f->job->creator->ulid]])
        ->assertOk()->assertExactJson(['data' => []]);
});

it('omits a creator whose block has ended, and one who was only invited', function (AssignmentStatus $status): void {
    $f = ExclusivityFixture::make();
    $f->select($status);

    $this->actingAs(conflictsAdmin($f))->postJson(conflictsUrl($f), ['creator_ids' => [$f->job->creator->ulid]])
        ->assertOk()->assertExactJson(['data' => []]);
})->with([
    'declined' => [AssignmentStatus::Declined],
    'invited' => [AssignmentStatus::Invited],
    'live_verified' => [AssignmentStatus::LiveVerified],
]);

it('names the EARLIEST blocking campaign when several block the same creator', function (): void {
    $f = ExclusivityFixture::make();
    $f->select(AssignmentStatus::Accepted);

    $later = Campaign::factory()->blocking([$f->job->brand])->createOne([
        'agency_id' => $f->job->agency->id,
        'brand_id' => $f->zava->id,
        'name' => 'Later campaign',
        'status' => CampaignStatus::Active,
    ]);
    CampaignAssignment::factory()->createOne([
        'campaign_id' => $later->id,
        'creator_id' => $f->job->creator->id,
        'status' => AssignmentStatus::Accepted,
    ]);

    $this->actingAs(conflictsAdmin($f))->postJson(conflictsUrl($f), ['creator_ids' => [$f->job->creator->ulid]])
        ->assertOk()
        ->assertJsonPath("data.{$f->job->creator->ulid}.blocking_campaign.name", ExclusivityFixture::ZAVA_CAMPAIGN_NAME);
});

it('never reports a campaign of another agency, even when a pivot row links it to this brand', function (): void {
    $f = ExclusivityFixture::make(zavaCampaignOverrides: []);
    $foreignAgency = Agency::factory()->createOne();
    $foreignBrand = Brand::factory()->forAgency($foreignAgency->id)->createOne();
    $foreign = Campaign::factory()->createOne([
        'agency_id' => $foreignAgency->id,
        'brand_id' => $foreignBrand->id,
        'name' => 'Foreign secret campaign',
        'status' => CampaignStatus::Active,
    ]);
    DB::table('campaign_exclusive_brands')->insert([
        'campaign_id' => $foreign->id,
        'brand_id' => $f->job->brand->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    CampaignAssignment::factory()->createOne([
        'campaign_id' => $foreign->id,
        'creator_id' => $f->job->creator->id,
        'status' => AssignmentStatus::Accepted,
    ]);

    $body = $this->actingAs(conflictsAdmin($f))->postJson(conflictsUrl($f), ['creator_ids' => [$f->job->creator->ulid]])
        ->assertOk()->assertExactJson(['data' => []])->getContent();

    expect($body)->not->toContain('Foreign secret campaign');
});

it('omits an unknown creator ULID rather than failing', function (): void {
    $f = ExclusivityFixture::make();

    $this->actingAs(conflictsAdmin($f))->postJson(conflictsUrl($f), ['creator_ids' => ['01HZZZZZZZZZZZZZZZZZZZZZZZ']])
        ->assertOk()->assertExactJson(['data' => []]);
});

it('validates the body: a list is required, capped at 100, and distinct', function (): void {
    $f = ExclusivityFixture::make();
    $admin = conflictsAdmin($f);

    $this->actingAs($admin)->postJson(conflictsUrl($f), [])->assertStatus(422);
    $this->actingAs($admin)->postJson(conflictsUrl($f), ['creator_ids' => array_fill(0, 101, 'x')])->assertStatus(422);
    $this->actingAs($admin)->postJson(conflictsUrl($f), ['creator_ids' => ['a', 'a']])->assertStatus(422);
});

it('is read-only: writes no audit row and changes no assignment', function (): void {
    $f = ExclusivityFixture::make();
    $f->select(AssignmentStatus::Accepted);
    $admin = conflictsAdmin($f);
    $audits = AuditLog::query()->count();
    $assignments = CampaignAssignment::withoutGlobalScopes()->count();

    $this->actingAs($admin)->postJson(conflictsUrl($f), ['creator_ids' => [$f->job->creator->ulid]])->assertOk();

    expect(AuditLog::query()->count())->toBe($audits)
        ->and(CampaignAssignment::withoutGlobalScopes()->count())->toBe($assignments);
});

it('is a 404 for another agency\'s campaign, and refused to a non-member', function (): void {
    $f = ExclusivityFixture::make();
    $otherAgency = Agency::factory()->createOne();
    $otherAdmin = User::factory()->agencyAdmin($otherAgency)->createOne();

    $this->actingAs($otherAdmin)
        ->postJson("/api/v1/agencies/{$otherAgency->ulid}/campaigns/{$f->job->campaign->ulid}/exclusivity-conflicts", ['creator_ids' => [$f->job->creator->ulid]])
        ->assertStatus(404);

    $status = $this->actingAs($f->job->user)->postJson(conflictsUrl($f), ['creator_ids' => [$f->job->creator->ulid]])->getStatusCode();
    expect($status)->toBeIn([401, 403, 404]);
});
