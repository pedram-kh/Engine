<?php

declare(strict_types=1);

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Enums\VerificationLevel;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorKycVerification;
use App\Modules\Creators\Models\CreatorPayoutMethod;
use App\Modules\Creators\Models\CreatorPortfolioItem;
use App\Modules\Creators\Models\CreatorSocialAccount;
use App\Modules\Creators\Models\CreatorTaxProfile;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('Creator factory creates a row with sane defaults', function (): void {
    $creator = Creator::factory()->createOne();

    expect($creator->ulid)->toBeString()->toHaveLength(26)
        ->and($creator->verification_level)->toBe(VerificationLevel::Unverified)
        ->and($creator->application_status)->toBe(ApplicationStatus::Incomplete)
        ->and($creator->kyc_status)->toBe(KycStatus::None)
        ->and($creator->profile_completeness_score)->toBe(0)
        ->and($creator->tax_profile_complete)->toBeFalse()
        ->and($creator->payout_method_set)->toBeFalse();
});

it('Creator bootstrap state has only required fields populated', function (): void {
    $creator = Creator::factory()->bootstrap()->createOne();

    expect($creator->display_name)->toBeNull()
        ->and($creator->country_code)->toBeNull()
        ->and($creator->primary_language)->toBeNull()
        ->and($creator->categories)->toBeNull()
        ->and($creator->application_status)->toBe(ApplicationStatus::Incomplete);
});

it('Creator user relationship resolves', function (): void {
    $creator = Creator::factory()->createOne();
    /** @var User $user */
    $user = $creator->user()->firstOrFail();

    expect($user->id)->toBe($creator->user_id);
});

it('Creator hasMany social_accounts', function (): void {
    $creator = Creator::factory()->createOne();
    CreatorSocialAccount::factory()->primary()->create([
        'creator_id' => $creator->id,
    ]);

    /** @var CreatorSocialAccount $first */
    $first = $creator->socialAccounts()->firstOrFail();

    expect($creator->socialAccounts)->toHaveCount(1)
        ->and($first->is_primary)->toBeTrue();
});

it('Creator hasMany portfolio_items ordered by position', function (): void {
    $creator = Creator::factory()->createOne();
    CreatorPortfolioItem::factory()->atPosition(3)->create(['creator_id' => $creator->id]);
    CreatorPortfolioItem::factory()->atPosition(1)->create(['creator_id' => $creator->id]);
    CreatorPortfolioItem::factory()->atPosition(2)->create(['creator_id' => $creator->id]);

    $positions = $creator->portfolioItems->pluck('position')->all();
    expect($positions)->toBe([1, 2, 3]);
});

it('Creator hasOne taxProfile', function (): void {
    $creator = Creator::factory()->createOne();
    CreatorTaxProfile::factory()->create(['creator_id' => $creator->id]);
    $creator->refresh();

    expect($creator->taxProfile)->toBeInstanceOf(CreatorTaxProfile::class);
});

it('Creator hasOne default payout_method', function (): void {
    $creator = Creator::factory()->createOne();
    CreatorPayoutMethod::factory()->create([
        'creator_id' => $creator->id,
        'is_default' => false,
    ]);
    CreatorPayoutMethod::factory()->create([
        'creator_id' => $creator->id,
        'is_default' => true,
    ]);
    $creator->refresh();

    /** @var CreatorPayoutMethod $defaultMethod */
    $defaultMethod = $creator->payoutMethod()->firstOrFail();
    expect($defaultMethod->is_default)->toBeTrue()
        ->and($creator->payoutMethods)->toHaveCount(2);
});

it('Creator hasMany kyc_verifications', function (): void {
    $creator = Creator::factory()->createOne();
    CreatorKycVerification::factory()->create(['creator_id' => $creator->id]);
    CreatorKycVerification::factory()->passed()->create(['creator_id' => $creator->id]);
    $creator->refresh();

    expect($creator->kycVerifications)->toHaveCount(2);
});

it('AgencyCreatorRelation supports prospect state with invitation columns', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $creator = Creator::factory()->bootstrap()->createOne();

    $relation = AgencyCreatorRelation::factory()
        ->prospect('test-token-secret')
        ->createOne([
            'agency_id' => $agency->id,
            'creator_id' => $creator->id,
        ]);

    expect($relation->relationship_status)->toBe(RelationshipStatus::Prospect)
        ->and($relation->invitation_token_hash)->toBe(hash('sha256', 'test-token-secret'))
        ->and($relation->invitation_expires_at)->not->toBeNull()
        ->and($relation->invitation_sent_at)->not->toBeNull()
        ->and($relation->invited_by_user_id)->not->toBeNull();
});

it('AgencyCreatorRelation isProspect helper distinguishes states', function (): void {
    $relation = AgencyCreatorRelation::factory()
        ->prospect()
        ->createOne();
    expect($relation->isProspect())->toBeTrue();

    $rosterRelation = AgencyCreatorRelation::factory()->createOne();
    expect($rosterRelation->isProspect())->toBeFalse();
});

it('AgencyCreatorRelation isInvitationExpired returns true after expiry', function (): void {
    $relation = AgencyCreatorRelation::factory()
        ->prospect()
        ->createOne([
            'invitation_expires_at' => now()->subDay(),
        ]);

    expect($relation->isInvitationExpired())->toBeTrue();
});

it('User->creator returns the creator satellite when present', function (): void {
    $user = User::factory()->creator()->createOne();
    $creator = Creator::factory()->createOne(['user_id' => $user->id]);
    $user->refresh();

    /** @var Creator $satellite */
    $satellite = $user->creator()->firstOrFail();
    expect($satellite->id)->toBe($creator->id);
});

it('User->creator is null for non-creator types', function (): void {
    $user = User::factory()->agencyAdmin()->createOne();
    $user->refresh();

    expect($user->creator()->first())->toBeNull();
});
