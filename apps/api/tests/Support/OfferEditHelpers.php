<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\ContractKind;
use App\Modules\Creators\Enums\ContractStatus;
use App\Modules\Creators\Features\AssignmentOfferEditEnabled;
use App\Modules\Creators\Models\Contract;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;

/*
|--------------------------------------------------------------------------
| AH-118 — shared fixtures for the offer-edit specs
|--------------------------------------------------------------------------
| Plain functions (the repo's Pest idiom), loaded with require_once so each
| spec file runs on its own and in any order.
*/

/**
 * @return array{agency: Agency, campaign: Campaign, assignment: CampaignAssignment, admin: User}
 */
function offerEditSetup(AssignmentStatus $status = AssignmentStatus::Invited, bool $flag = true): array
{
    // The queue is `sync` under test and Pennant flushes its array store when a
    // queued job finishes, which would silently turn the flag back OFF mid-test.
    Mail::fake();

    $flag
        ? Feature::activate(AssignmentOfferEditEnabled::NAME)
        : Feature::deactivate(AssignmentOfferEditEnabled::NAME);

    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne(['agency_id' => $agency->id, 'brand_id' => $brand->id, 'budget_currency' => 'EUR']);
    $creator = CreatorFactory::new()->approved()->createOne(['user_id' => User::factory()->createOne()->id]);

    $assignment = CampaignAssignment::factory()->status($status)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $brand->id,
        'creator_id' => $creator->id,
        'agreed_fee_minor_units' => 100_00,
        'agreed_fee_currency' => 'EUR',
        'fee_per' => 'video',
        'contract_id' => null,
        'payment_id' => null,
    ]);

    return [
        'agency' => $agency,
        'campaign' => $campaign,
        'assignment' => $assignment,
        'admin' => User::factory()->agencyAdmin($agency)->createOne(),
    ];
}

function offerEditUrl(Agency $agency, Campaign $campaign, CampaignAssignment $assignment): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments/{$assignment->ulid}/offer";
}

function sendContractFor(CampaignAssignment $assignment, ContractStatus $status = ContractStatus::Sent): Contract
{
    return Contract::query()->create([
        'agency_id' => $assignment->agency_id,
        'kind' => ContractKind::PerCampaign,
        'subject_type' => Contract::SUBJECT_CAMPAIGN_ASSIGNMENT,
        'subject_id' => $assignment->id,
        'version' => 1,
        'title' => 'Addendum',
        'body_markdown' => 'Terms.',
        'signature_provider' => Contract::PROVIDER_INTERNAL,
        'status' => $status,
        'sent_at' => now(),
    ]);
}
