<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Mail\ContractAcceptedMail;
use App\Modules\Campaigns\Mail\ContractAttachedMail;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Policies\CampaignPolicy;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\ContractKind;
use App\Modules\Creators\Enums\ContractStatus;
use App\Modules\Creators\Features\ContractSigningEnabled;
use App\Modules\Creators\Models\Contract;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Contract-bridge chunk — the accepted → contracted manual flow (D-1..D-9).
 * Agency attach + creator accept, flag-ON gate, authz, chain-through to draft.
 */

/**
 * @return array{0: Agency, 1: Campaign, 2: CampaignAssignment, 3: Creator, 4: User}
 */
function contractSetup(AssignmentStatus $status = AssignmentStatus::Accepted): array
{
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne(['agency_id' => $agency->id, 'brand_id' => $brand->id]);
    $inviter = User::factory()->agencyAdmin($agency)->createOne();
    $creatorUser = User::factory()->createOne();
    $creator = CreatorFactory::new()->approved()->createOne(['user_id' => $creatorUser->id]);

    $assignment = CampaignAssignment::factory()->status($status)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $brand->id,
        'creator_id' => $creator->id,
        'invited_by_user_id' => $inviter->id,
    ]);

    return [$agency, $campaign, $assignment, $creator, $creatorUser];
}

function agencyContractUrl(Agency $agency, Campaign $campaign, CampaignAssignment $assignment, string $suffix): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments/{$assignment->ulid}/contract/{$suffix}";
}

function creatorAcceptUrl(CampaignAssignment $assignment): string
{
    return "/api/v1/creators/me/assignments/{$assignment->ulid}/contract/accept";
}

beforeEach(function (): void {
    Feature::define(ContractSigningEnabled::NAME, true);
    Storage::fake('media');
});

it('agency attach writes a per_campaign contract row and notifies the creator', function (): void {
    Mail::fake();
    [$agency, $campaign, $assignment] = contractSetup();
    $staff = User::factory()->agencyStaff($agency)->createOne();

    $this->actingAs($staff)
        ->postJson(agencyContractUrl($agency, $campaign, $assignment, 'attach'), [
            'title' => 'Campaign addendum',
            'body_markdown' => 'You agree to deliver one Reel by the due date.',
        ])
        ->assertCreated()
        ->assertJsonPath('meta.code', 'contract.attached')
        ->assertJsonPath('data.attributes.kind', 'per_campaign')
        ->assertJsonPath('data.attributes.status', 'sent');

    $contract = Contract::query()->first();
    expect($contract)->not->toBeNull()
        ->and($contract?->kind)->toBe(ContractKind::PerCampaign)
        ->and($contract?->subject_type)->toBe(Contract::SUBJECT_CAMPAIGN_ASSIGNMENT)
        ->and($contract?->subject_id)->toBe($assignment->id)
        ->and($contract?->status)->toBe(ContractStatus::Sent)
        ->and($assignment->fresh()?->status)->toBe(AssignmentStatus::Accepted)
        ->and($assignment->fresh()?->contract_id)->toBeNull();

    Mail::assertQueued(ContractAttachedMail::class);
});

it('creator accept stamps signed_at, fires contract(), sets contract_id, and notifies agency', function (): void {
    Mail::fake();
    [$agency, $campaign, $assignment, $creator, $creatorUser] = contractSetup();

    $this->actingAs(User::factory()->agencyStaff($agency)->createOne())
        ->postJson(agencyContractUrl($agency, $campaign, $assignment, 'attach'), [
            'title' => 'Addendum',
            'body_markdown' => 'Terms here.',
        ])
        ->assertCreated();

    $this->actingAs($creatorUser)
        ->postJson(creatorAcceptUrl($assignment))
        ->assertOk()
        ->assertJsonPath('meta.code', 'assignment.contracted')
        ->assertJsonPath('data.attributes.status', 'contracted');

    $fresh = $assignment->fresh();
    $contract = Contract::query()->first();

    expect($fresh?->status)->toBe(AssignmentStatus::Contracted)
        ->and($fresh?->contract_id)->toBe($contract?->id)
        ->and($contract?->status)->toBe(ContractStatus::Signed)
        ->and($contract?->signed_at)->not->toBeNull()
        ->and($contract?->signed_by_creator_id)->toBe($creator->id);

    expect(AuditLog::query()->where('action', 'assignment.contracted')->where('subject_id', $assignment->id)->exists())->toBeTrue();

    Mail::assertQueued(ContractAcceptedMail::class);
});

it('accept on non-accepted assignment is rejected fail-closed', function (): void {
    [$agency, $campaign, $assignment, , $creatorUser] = contractSetup(AssignmentStatus::Invited);

    $this->actingAs($creatorUser)
        ->postJson(creatorAcceptUrl($assignment))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'assignment.not_accepted');
});

it('accept on another creators assignment returns 404', function (): void {
    [$agency, $campaign, $assignment] = contractSetup();
    $otherUser = User::factory()->createOne();
    CreatorFactory::new()->approved()->createOne(['user_id' => $otherUser->id]);

    $this->actingAs(User::factory()->agencyStaff($agency)->createOne())
        ->postJson(agencyContractUrl($agency, $campaign, $assignment, 'attach'), [
            'title' => 'Addendum',
            'body_markdown' => 'Terms.',
        ])
        ->assertCreated();

    $this->actingAs($otherUser)
        ->postJson(creatorAcceptUrl($assignment))
        ->assertNotFound();
});

it('attach is unavailable when contract_signing_enabled is OFF', function (): void {
    Feature::define(ContractSigningEnabled::NAME, false);
    [$agency, $campaign, $assignment] = contractSetup();
    $staff = User::factory()->agencyStaff($agency)->createOne();

    $this->actingAs($staff)
        ->postJson(agencyContractUrl($agency, $campaign, $assignment, 'attach'), [
            'title' => 'Addendum',
            'body_markdown' => 'Terms.',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'assignment.contract_signing_disabled');
});

it('accept is unavailable when contract_signing_enabled is OFF', function (): void {
    [$agency, $campaign, $assignment, , $creatorUser] = contractSetup();

    $this->actingAs(User::factory()->agencyStaff($agency)->createOne())
        ->postJson(agencyContractUrl($agency, $campaign, $assignment, 'attach'), [
            'title' => 'Addendum',
            'body_markdown' => 'Terms.',
        ])
        ->assertCreated();

    Feature::define(ContractSigningEnabled::NAME, false);

    $this->actingAs($creatorUser)
        ->postJson(creatorAcceptUrl($assignment))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'assignment.contract_signing_disabled');
});

it('attach authz allows admin manager staff and rejects non-members with 404', function (): void {
    [$agency, $campaign, $assignment] = contractSetup();
    $payload = ['title' => 'Addendum', 'body_markdown' => 'Terms.'];

    $this->actingAs(User::factory()->agencyAdmin($agency)->createOne())
        ->postJson(agencyContractUrl($agency, $campaign, $assignment, 'attach'), $payload)
        ->assertCreated();

    [$agency2, $campaign2, $assignment2] = contractSetup();
    $outsider = User::factory()->createOne();

    $this->actingAs($outsider)
        ->postJson(agencyContractUrl($agency2, $campaign2, $assignment2, 'attach'), $payload)
        ->assertNotFound();
});

it('attachContract policy mirrors invite and review roles', function (): void {
    [$agency, $campaign] = contractSetup();
    $policy = new CampaignPolicy;

    expect($policy->attachContract(User::factory()->agencyAdmin($agency)->createOne(), $campaign))->toBeTrue()
        ->and($policy->attachContract(User::factory()->agencyManager($agency)->createOne(), $campaign))->toBeTrue()
        ->and($policy->attachContract(User::factory()->agencyStaff($agency)->createOne(), $campaign))->toBeTrue();
});

it('presigned contract upload completes under agency assignment prefix', function (): void {
    [$agency, $campaign, $assignment] = contractSetup();
    $staff = User::factory()->agencyStaff($agency)->createOne();

    $init = $this->actingAs($staff)
        ->postJson(agencyContractUrl($agency, $campaign, $assignment, 'media/init'), [
            'mime_type' => 'application/pdf',
            'declared_bytes' => 1024,
        ])
        ->assertOk()
        ->json('data');

    Storage::disk('media')->put($init['upload_id'], 'pdf-bytes');

    $this->actingAs($staff)
        ->postJson(agencyContractUrl($agency, $campaign, $assignment, 'media/complete'), [
            'upload_id' => $init['upload_id'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.storage_path', $init['upload_id']);
});

it('after accept the existing draft submit flow works from contracted', function (): void {
    [$agency, $campaign, $assignment, , $creatorUser] = contractSetup();
    $staff = User::factory()->agencyStaff($agency)->createOne();

    $this->actingAs($staff)
        ->postJson(agencyContractUrl($agency, $campaign, $assignment, 'attach'), [
            'title' => 'Addendum',
            'body_markdown' => 'Terms.',
        ])
        ->assertCreated();

    $this->actingAs($creatorUser)
        ->postJson(creatorAcceptUrl($assignment))
        ->assertOk();

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::Contracted);

    $creator = $assignment->creator;
    $mediaPath = "creators/{$creator->ulid}/drafts/test.mp4";
    Storage::disk('media')->put($mediaPath, 'video');

    $this->actingAs($creatorUser)
        ->postJson("/api/v1/creators/me/assignments/{$assignment->ulid}/drafts", [
            'caption' => 'Draft v1',
            'media' => [[
                's3_path' => $mediaPath,
                'mime_type' => 'video/mp4',
                'kind' => 'video',
            ]],
        ])
        ->assertCreated()
        ->assertJsonPath('meta.code', 'assignment.draft_submitted');

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::DraftSubmitted);
});

it('creator show includes pending contract relationship when accepted', function (): void {
    [$agency, $campaign, $assignment, , $creatorUser] = contractSetup();

    $this->actingAs(User::factory()->agencyStaff($agency)->createOne())
        ->postJson(agencyContractUrl($agency, $campaign, $assignment, 'attach'), [
            'title' => 'Addendum',
            'body_markdown' => 'Review these terms.',
        ])
        ->assertCreated();

    $this->actingAs($creatorUser)
        ->getJson("/api/v1/creators/me/assignments/{$assignment->ulid}")
        ->assertOk()
        ->assertJsonPath('data.relationships.contract.attributes.status', 'sent')
        ->assertJsonPath('data.relationships.contract.attributes.body_markdown', 'Review these terms.');
});
