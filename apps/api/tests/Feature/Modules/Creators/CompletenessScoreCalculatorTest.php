<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Database\Factories\CreatorPortfolioItemFactory;
use App\Modules\Creators\Database\Factories\CreatorSocialAccountFactory;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Enums\WizardStep;
use App\Modules\Creators\Features\ContractSigningEnabled;
use App\Modules\Creators\Features\CreatorPayoutMethodEnabled;
use App\Modules\Creators\Features\KycVerificationEnabled;
use App\Modules\Creators\Services\CompletenessScoreCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    // Sprint 3 Chunk 2 sub-step 9 — completeness now honours
    // flag-OFF skip-paths (KYC / payout / contract steps satisfy
    // when their gating flag is OFF). These tests pin the
    // flag-ON weighting; flag-OFF coverage lives in
    // CreatorWizardFlagOffTest.
    Feature::activate(KycVerificationEnabled::NAME);
    Feature::activate(ContractSigningEnabled::NAME);
    Feature::activate(CreatorPayoutMethodEnabled::NAME);
});

/*
|--------------------------------------------------------------------------
| CompletenessScoreCalculator — source-inspection regression test (#1)
|--------------------------------------------------------------------------
|
| Pins the weight values + sum-to-100 invariant. If a future contributor
| changes the weighting without updating this test, the test fails; the
| failure surfaces the change to the reviewer.
*/

it('weights sum to exactly 100', function (): void {
    $calc = new CompletenessScoreCalculator;

    expect(array_sum($calc->weights()))->toBe(100);
});

it('weights cover every wizard step except Review', function (): void {
    $calc = new CompletenessScoreCalculator;
    $weights = $calc->weights();

    $expected = collect(WizardStep::ordered())
        ->reject(fn (WizardStep $s): bool => $s === WizardStep::Review)
        ->map(fn (WizardStep $s): string => $s->value)
        ->sort()
        ->values()
        ->all();

    $actual = collect(array_keys($weights))->sort()->values()->all();

    expect($actual)->toBe($expected);
});

it('weight values match the documented Sprint-3 weighting (regression pin)', function (): void {
    $calc = new CompletenessScoreCalculator;

    expect($calc->weights())->toBe([
        WizardStep::Profile->value => 25,
        WizardStep::Social->value => 15,
        WizardStep::Portfolio->value => 10,
        WizardStep::Kyc->value => 15,
        WizardStep::Tax->value => 10,
        WizardStep::Payout->value => 10,
        WizardStep::Contract->value => 15,
    ]);
});

it('a freshly-bootstrapped creator scores 0', function (): void {
    $creator = CreatorFactory::new()->bootstrap()->createOne();
    $calc = new CompletenessScoreCalculator;

    expect($calc->score($creator))->toBe(0);
});

it('a fully-completed creator scores 100', function (): void {
    $creator = CreatorFactory::new()->createOne([
        'display_name' => 'Test Creator',
        'country_code' => 'IT',
        'primary_language' => 'en',
        'categories' => ['lifestyle'],
        'avatar_path' => 'creators/test/avatar/abc.jpg',
        'kyc_status' => KycStatus::Verified,
        'tax_profile_complete' => true,
        'payout_method_set' => true,
        'signed_master_contract_id' => 1,
    ]);

    CreatorSocialAccountFactory::new()->for($creator)->create();
    CreatorPortfolioItemFactory::new()->for($creator)->create();

    $calc = new CompletenessScoreCalculator;

    expect($calc->score($creator))->toBe(100);
});

it('next_step starts at profile and advances as steps complete', function (): void {
    $creator = CreatorFactory::new()->bootstrap()->createOne();
    $calc = new CompletenessScoreCalculator;

    expect($calc->nextStep($creator))->toBe(WizardStep::Profile);

    $creator->update([
        'display_name' => 'Test',
        'country_code' => 'IT',
        'primary_language' => 'en',
        'categories' => ['music'],
        'avatar_path' => 'x',
    ]);

    expect($calc->nextStep($creator->refresh()))->toBe(WizardStep::Social);
});

it('next_step lands on Review when every preceding step is complete', function (): void {
    $creator = CreatorFactory::new()->createOne([
        'display_name' => 'Done',
        'country_code' => 'IT',
        'primary_language' => 'en',
        'categories' => ['music'],
        'avatar_path' => 'x',
        'kyc_status' => KycStatus::Verified,
        'tax_profile_complete' => true,
        'payout_method_set' => true,
        'signed_master_contract_id' => 1,
    ]);
    CreatorSocialAccountFactory::new()->for($creator)->create();
    CreatorPortfolioItemFactory::new()->for($creator)->create();

    $calc = new CompletenessScoreCalculator;

    expect($calc->nextStep($creator->refresh()))->toBe(WizardStep::Review);
});
