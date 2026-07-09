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

it('the D4 profile sub-split (floor + optionals) sums to the profile unit weight', function (): void {
    // The floor block + every optional field's credit must equal the profile
    // unit's total weight (25). This is the invariant that keeps the
    // denominator, the sum-to-100 total, and every other unit's ratio
    // undisturbed by the partial-credit split.
    $calc = new CompletenessScoreCalculator;

    $splitTotal = CompletenessScoreCalculator::PROFILE_FLOOR_WEIGHT
        + array_sum(CompletenessScoreCalculator::PROFILE_OPTIONAL_WEIGHTS);

    expect($splitTotal)->toBe($calc->weights()[WizardStep::Profile->value]);
});

it('the D4 profile sub-split values match the documented split (regression pin)', function (): void {
    expect(CompletenessScoreCalculator::PROFILE_FLOOR_WEIGHT)->toBe(13);
    expect(CompletenessScoreCalculator::PROFILE_OPTIONAL_WEIGHTS)->toBe([
        'bio' => 4,
        'accent' => 2,
        'phone' => 2,
        'whatsapp' => 2,
        'address_street' => 1,
        'address_postal_code' => 1,
    ]);
});

it('a freshly-bootstrapped creator scores 0', function (): void {
    $creator = CreatorFactory::new()->bootstrap()->createOne();
    $calc = new CompletenessScoreCalculator;

    expect($calc->score($creator))->toBe(0);
});

it('a fully-completed creator scores 100', function (): void {
    // D4: the profile unit only reaches its full 25 when the six-field floor
    // (region included, D1) AND every optional field are filled — so a
    // "fully-completed" fixture must now set the optionals too, otherwise the
    // profile unit lands at PROFILE_FLOOR_WEIGHT (13) and the total is < 100.
    $creator = CreatorFactory::new()->createOne([
        'display_name' => 'Test Creator',
        'country_code' => 'IT',
        'region' => 'Lazio',
        'primary_language' => 'en',
        'categories' => ['lifestyle'],
        'avatar_path' => 'creators/test/avatar/abc.jpg',
        'bio' => 'A short bio.',
        'accent' => 'Roman',
        'phone' => '+39 06 1234 5678',
        'whatsapp' => '+39 06 1234 5679',
        'address_street' => 'Via del Corso 1',
        'address_postal_code' => '00186',
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
        'region' => 'Lazio',
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
        'region' => 'Lazio',
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

/*
|--------------------------------------------------------------------------
| D1 — region joins the floor
|--------------------------------------------------------------------------
*/

it('a creator missing only region does NOT complete the profile step (D1 floor)', function (): void {
    $creator = CreatorFactory::new()->createOne([
        'display_name' => 'No Region',
        'country_code' => 'IT',
        'region' => null,
        'primary_language' => 'en',
        'categories' => ['music'],
        'avatar_path' => 'x',
    ]);

    $calc = new CompletenessScoreCalculator;

    expect($calc->stepCompletion($creator)[WizardStep::Profile->value])->toBeFalse();
    expect($calc->nextStep($creator))->toBe(WizardStep::Profile);
});

it('filling region flips the profile step complete (D1 floor)', function (): void {
    $creator = CreatorFactory::new()->createOne([
        'display_name' => 'With Region',
        'country_code' => 'IT',
        'region' => 'Lazio',
        'primary_language' => 'en',
        'categories' => ['music'],
        'avatar_path' => 'x',
    ]);

    $calc = new CompletenessScoreCalculator;

    expect($calc->stepCompletion($creator)[WizardStep::Profile->value])->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| D4 — profile partial credit (gate boolean vs score numerator)
|--------------------------------------------------------------------------
| booleans gate, points motivate. The floor block earns 13; each filled
| optional adds its own points, independent of the floor. §5.34 negative
| case: an empty optional must NOT flip stepCompletion['profile'] false.
*/

it('profileEarned awards the floor block only when the six-field floor is met', function (): void {
    $calc = new CompletenessScoreCalculator;

    $atFloor = CreatorFactory::new()->createOne([
        'display_name' => 'Floor Only',
        'country_code' => 'IT',
        'region' => 'Lazio',
        'primary_language' => 'en',
        'categories' => ['music'],
        'avatar_path' => 'x',
        'bio' => null,
        'accent' => null,
        'phone' => null,
        'whatsapp' => null,
        'address_street' => null,
        'address_postal_code' => null,
    ]);

    expect($calc->profileEarned($atFloor))->toBe(CompletenessScoreCalculator::PROFILE_FLOOR_WEIGHT);
});

it('profileEarned awards optional credit independently of the floor (Q2)', function (): void {
    $calc = new CompletenessScoreCalculator;

    // Below the floor (no display_name/region/etc.) but with a bio + accent:
    // the meter still moves — 4 (bio) + 2 (accent) = 6 — even though the
    // floor block earns nothing and the gate boolean stays false.
    $belowFloor = CreatorFactory::new()->bootstrap()->createOne([
        'bio' => 'Just a bio.',
        'accent' => 'Roman',
    ]);

    expect($calc->profileEarned($belowFloor))->toBe(6);
    expect($calc->stepCompletion($belowFloor)[WizardStep::Profile->value])->toBeFalse();
});

it('an empty optional drops the score but does NOT flip the profile gate (§5.34)', function (): void {
    $calc = new CompletenessScoreCalculator;

    $creator = CreatorFactory::new()->createOne([
        'display_name' => 'Gated',
        'country_code' => 'IT',
        'region' => 'Lazio',
        'primary_language' => 'en',
        'categories' => ['music'],
        'avatar_path' => 'x',
        'bio' => 'A bio worth four points.',
    ]);

    // Floor met (gate true) + bio filled → profile earns 13 + 4 = 17.
    expect($calc->stepCompletion($creator)[WizardStep::Profile->value])->toBeTrue();
    expect($calc->profileEarned($creator))->toBe(17);

    // Clear the optional: the score drops by the bio weight, the GATE holds.
    $creator->update(['bio' => null]);
    $creator->refresh();

    expect($calc->stepCompletion($creator)[WizardStep::Profile->value])->toBeTrue();
    expect($calc->profileEarned($creator))->toBe(CompletenessScoreCalculator::PROFILE_FLOOR_WEIGHT);
});

it('whitespace-only optionals are not "filled" (trimmed-non-empty parity)', function (): void {
    $calc = new CompletenessScoreCalculator;

    $creator = CreatorFactory::new()->createOne([
        'display_name' => 'Whitespace',
        'country_code' => 'IT',
        'region' => 'Lazio',
        'primary_language' => 'en',
        'categories' => ['music'],
        'avatar_path' => 'x',
        'bio' => '   ',
        'accent' => '',
    ]);

    // Neither optional counts — profile earns the floor block only.
    expect($calc->profileEarned($creator))->toBe(CompletenessScoreCalculator::PROFILE_FLOOR_WEIGHT);
});
