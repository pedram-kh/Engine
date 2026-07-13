<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Features\PerCampaignContractEnabled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * D4 — the one-shot stuck-row remediation command
 * (`campaigns:advance-contractless-accepted`). Scope: `accepted` only, on
 * `requires=false` campaigns only. Idempotent, --dry-run, flag-independent,
 * audit-distinguishable (source: backfill).
 */
function acceptedAssignmentOn(bool $requiresContract, AssignmentStatus $status = AssignmentStatus::Accepted): CampaignAssignment
{
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne([
        'agency_id' => $agency->id,
        'brand_id' => $brand->id,
        'requires_per_campaign_contract' => $requiresContract,
    ]);
    $creator = CreatorFactory::new()->approved()->createOne();

    return CampaignAssignment::factory()->status($status)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $brand->id,
        'creator_id' => $creator->id,
    ]);
}

it('advances a stuck accepted assignment on a requires=false campaign to contracted with no contract', function (): void {
    $assignment = acceptedAssignmentOn(requiresContract: false);

    $this->artisan('campaigns:advance-contractless-accepted')
        ->expectsOutputToContain('1 advanced to contracted')
        ->assertExitCode(0);

    $fresh = $assignment->fresh();
    expect($fresh?->status)->toBe(AssignmentStatus::Contracted)
        ->and($fresh?->contract_id)->toBeNull();
});

it('is idempotent — a second run advances nothing', function (): void {
    acceptedAssignmentOn(requiresContract: false);

    $this->artisan('campaigns:advance-contractless-accepted')
        ->expectsOutputToContain('1 advanced to contracted')
        ->assertExitCode(0);

    $this->artisan('campaigns:advance-contractless-accepted')
        ->expectsOutputToContain('0 advanced to contracted')
        ->assertExitCode(0);
});

it('skips requires=true campaigns (the contract path is left intact, D7)', function (): void {
    $assignment = acceptedAssignmentOn(requiresContract: true);

    $this->artisan('campaigns:advance-contractless-accepted')
        ->expectsOutputToContain('0 advanced to contracted')
        ->assertExitCode(0);

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::Accepted);
});

it('skips non-accepted states (invited rows advance via the normal accept path)', function (): void {
    $invited = acceptedAssignmentOn(requiresContract: false, status: AssignmentStatus::Invited);

    $this->artisan('campaigns:advance-contractless-accepted')
        ->expectsOutputToContain('0 advanced to contracted')
        ->assertExitCode(0);

    expect($invited->fresh()?->status)->toBe(AssignmentStatus::Invited);
});

it('--dry-run reports the advance but leaves the row at accepted', function (): void {
    $assignment = acceptedAssignmentOn(requiresContract: false);

    $this->artisan('campaigns:advance-contractless-accepted --dry-run')
        ->expectsOutputToContain('1 would advance')
        ->assertExitCode(0);

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::Accepted);
});

it('runs regardless of the per_campaign_contract_enabled flag (Q2 — the flag is irrelevant to a contract-less advance)', function (): void {
    Feature::define(PerCampaignContractEnabled::NAME, false);
    $assignment = acceptedAssignmentOn(requiresContract: false);

    $this->artisan('campaigns:advance-contractless-accepted')
        ->expectsOutputToContain('1 advanced to contracted')
        ->assertExitCode(0);

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::Contracted);
});

it('stamps the backfill audit context so the path is distinguishable (D6)', function (): void {
    $assignment = acceptedAssignmentOn(requiresContract: false);

    $this->artisan('campaigns:advance-contractless-accepted')->assertExitCode(0);

    $audit = AuditLog::query()
        ->where('action', 'assignment.contracted')
        ->where('subject_type', $assignment->getMorphClass())
        ->where('subject_id', $assignment->id)
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit?->metadata['auto_advanced'] ?? null)->toBeTrue()
        ->and($audit?->metadata['source'] ?? null)->toBe('backfill');
});
