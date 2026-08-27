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
    // D4: the profile unit only reaches its full 25 when the seven-field floor
    // (region per D1, date_of_birth per AH-088 D6) AND every optional field are
    // filled — so a
    // "fully-completed" fixture must now set the optionals too, otherwise the
    // profile unit lands at PROFILE_FLOOR_WEIGHT (13) and the total is < 100.
    $creator = CreatorFactory::new()->createOne([
        'display_name' => 'Test Creator',
        'country_code' => 'IT',
        'region' => 'Lazio',
        'date_of_birth' => '1994-03-17',
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
        'date_of_birth' => '1994-03-17',
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
        'date_of_birth' => '1994-03-17',
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
        'date_of_birth' => '1994-03-17',
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
        'date_of_birth' => '1994-03-17',
        'primary_language' => 'en',
        'categories' => ['music'],
        'avatar_path' => 'x',
    ]);

    $calc = new CompletenessScoreCalculator;

    expect($calc->stepCompletion($creator)[WizardStep::Profile->value])->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| AH-088 D6 — date_of_birth joins the same floor
|--------------------------------------------------------------------------
|
| The region pair above, repeated for the birthday. Worth having twice: the
| floor is HAND-LISTED in isProfileComplete() (there is no field registry to
| iterate), so each member needs its own pin or a future edit can drop one
| silently. The frontend half of the mirror is enforced separately, by source
| inspection, in floor-mirror-parity.spec.ts.
|
*/

it('a creator missing only date_of_birth does NOT complete the profile step (AH-088 floor)', function (): void {
    $creator = CreatorFactory::new()->createOne([
        'display_name' => 'No Birthday',
        'country_code' => 'IT',
        'region' => 'Lazio',
        'date_of_birth' => null,
        'primary_language' => 'en',
        'categories' => ['music'],
        'avatar_path' => 'x',
    ]);

    $calc = new CompletenessScoreCalculator;

    expect($calc->stepCompletion($creator)[WizardStep::Profile->value])->toBeFalse();
    expect($calc->nextStep($creator))->toBe(WizardStep::Profile);
});

it('filling date_of_birth flips the profile step complete (AH-088 floor)', function (): void {
    $creator = CreatorFactory::new()->createOne([
        'display_name' => 'With Birthday',
        'country_code' => 'IT',
        'region' => 'Lazio',
        'date_of_birth' => '1994-03-17',
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
| booleans gate, points motivate. The floor block earns UP TO 13 — graduated
| by present-field count since AH-089 D2 (see the section below); these
| fixtures stay AT the full floor (7/7) so the optional-credit behaviour is
| isolated from the floor's own fraction. Each filled optional adds its own
| points, independent of the floor. §5.34 negative case: an empty optional
| must NOT flip stepCompletion['profile'] false.
*/

it('profileEarned awards the floor block only when the seven-field floor is met', function (): void {
    $calc = new CompletenessScoreCalculator;

    $atFloor = CreatorFactory::new()->createOne([
        'display_name' => 'Floor Only',
        'country_code' => 'IT',
        'region' => 'Lazio',
        'date_of_birth' => '1994-03-17',
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

    // AH-089: profileEarned() now returns float (the floor is graduated), but
    // the full-floor endpoint is a fixed point — 7/7 present still earns
    // exactly PROFILE_FLOOR_WEIGHT (13), today's number unchanged.
    expect($calc->profileEarned($atFloor))->toBe((float) CompletenessScoreCalculator::PROFILE_FLOOR_WEIGHT);
});

it('profileEarned awards optional credit independently of the floor (Q2)', function (): void {
    $calc = new CompletenessScoreCalculator;

    // Below the floor (no display_name/region/etc.) but with a bio + accent:
    // the meter still moves — 4 (bio) + 2 (accent) = 6 — even though the
    // floor block earns nothing (0/7 present, the other fixed point) and
    // the gate boolean stays false.
    $belowFloor = CreatorFactory::new()->bootstrap()->createOne([
        'bio' => 'Just a bio.',
        'accent' => 'Roman',
    ]);

    expect($calc->profileEarned($belowFloor))->toBe(6.0);
    expect($calc->stepCompletion($belowFloor)[WizardStep::Profile->value])->toBeFalse();
});

it('an empty optional drops the score but does NOT flip the profile gate (§5.34)', function (): void {
    $calc = new CompletenessScoreCalculator;

    $creator = CreatorFactory::new()->createOne([
        'display_name' => 'Gated',
        'country_code' => 'IT',
        'region' => 'Lazio',
        'date_of_birth' => '1994-03-17',
        'primary_language' => 'en',
        'categories' => ['music'],
        'avatar_path' => 'x',
        'bio' => 'A bio worth four points.',
    ]);

    // Floor met (gate true) + bio filled → profile earns 13 + 4 = 17.
    expect($calc->stepCompletion($creator)[WizardStep::Profile->value])->toBeTrue();
    expect($calc->profileEarned($creator))->toBe(17.0);

    // Clear the optional: the score drops by the bio weight, the GATE holds.
    $creator->update(['bio' => null]);
    $creator->refresh();

    expect($calc->stepCompletion($creator)[WizardStep::Profile->value])->toBeTrue();
    expect($calc->profileEarned($creator))->toBe((float) CompletenessScoreCalculator::PROFILE_FLOOR_WEIGHT);
});

it('whitespace-only optionals are not "filled" (trimmed-non-empty parity)', function (): void {
    $calc = new CompletenessScoreCalculator;

    $creator = CreatorFactory::new()->createOne([
        'display_name' => 'Whitespace',
        'country_code' => 'IT',
        'region' => 'Lazio',
        'date_of_birth' => '1994-03-17',
        'primary_language' => 'en',
        'categories' => ['music'],
        'avatar_path' => 'x',
        'bio' => '   ',
        'accent' => '',
    ]);

    // Neither optional counts — profile earns the floor block only.
    expect($calc->profileEarned($creator))->toBe((float) CompletenessScoreCalculator::PROFILE_FLOOR_WEIGHT);
});

/*
|--------------------------------------------------------------------------
| AH-089 — the floor becomes graduated (D2), pinned against the gate (D3)
|--------------------------------------------------------------------------
|
| The gate (isProfileComplete(), pinned above) stays all-or-nothing and
| completely untouched (D1). Its own weight (PROFILE_FLOOR_WEIGHT) is now
| earned in proportion to how many of the seven floor fields are present,
| via floorFieldPresence() — a SEPARATE enumeration, not a shared call,
| because isProfileComplete()'s literal source text is load-bearing for two
| specs this chunk does not edit: floor-mirror-parity.spec.ts and this
| file's own D6 source-pin (below). A structurally-impossible-to-diverge
| version — one function backing both the boolean and the fraction — does
| NOT exist while D1 holds those two specs' literal shape fixed. The
| one-source pin test right below is the documented substitute, in the
| house's own idiom rather than a new mechanism.
*/

it('AH-089 D3 — floorFieldPresence() checks EXACTLY the same fields as isProfileComplete() (one-source pin)', function (): void {
    // Same idiom the D6 pin below (and ContentCompanionsTest.php) already use
    // against isProfileComplete() alone — regex-extract the function body,
    // collect $creator->field tokens — applied here to BOTH enumerations and
    // compared. Break either one (add/drop a field from just one side) and
    // this test reds; that is the whole point of the pin.
    $source = (string) file_get_contents(
        (new ReflectionClass(CompletenessScoreCalculator::class))->getFileName() ?: '',
    );

    preg_match(
        '/private function isProfileComplete\(Creator \$creator\): bool\s*\{([\s\S]*?)\n {4}\}/',
        $source,
        $gate,
    );
    preg_match(
        '/private function floorFieldPresence\(Creator \$creator\): array\s*\{([\s\S]*?)\n {4}\}/',
        $source,
        $fraction,
    );

    preg_match_all('/\$creator->(\w+)/', $gate[1] ?? '', $gateTokens);
    preg_match_all('/\$creator->(\w+)/', $fraction[1] ?? '', $fractionTokens);

    $gateFields = collect($gateTokens[1])->unique()->sort()->values()->all();
    $fractionFields = collect($fractionTokens[1])->unique()->sort()->values()->all();

    expect($gateFields)->not->toBeEmpty();
    expect($fractionFields)->toBe($gateFields);
});

it('AH-089 D2 — missing exactly one floor field earns 13 × 6/7 of the floor block (dataset)', function (string $field, mixed $missingValue): void {
    $attrs = [
        'display_name' => 'Six Of Seven',
        'country_code' => 'IT',
        'region' => 'Lazio',
        'date_of_birth' => '1994-03-17',
        'primary_language' => 'en',
        'categories' => ['music'],
        'avatar_path' => 'x',
        // Every optional pinned null — the factory's own `bio` default is
        // randomised (fake()->optional()), which would otherwise sometimes
        // add uncontrolled optional credit and break this pin's isolation.
        'bio' => null,
        'accent' => null,
        'phone' => null,
        'whatsapp' => null,
        'address_street' => null,
        'address_postal_code' => null,
    ];
    $attrs[$field] = $missingValue;

    $creator = CreatorFactory::new()->createOne($attrs);
    $calc = new CompletenessScoreCalculator;

    // The gate still reads this creator as incomplete (D1, untouched) —
    // pinned alongside the fraction so the two never silently drift apart.
    expect($calc->stepCompletion($creator)[WizardStep::Profile->value])->toBeFalse();
    expect($calc->profileEarned($creator))->toBe(CompletenessScoreCalculator::PROFILE_FLOOR_WEIGHT * (6 / 7));
})->with([
    'display_name' => ['display_name', null],
    'country_code' => ['country_code', null],
    'region' => ['region', null],
    'date_of_birth' => ['date_of_birth', null],
    'primary_language' => ['primary_language', null],
    'categories' => ['categories', []],
    'avatar_path' => ['avatar_path', null],
]);

it('AH-089 — adding any floor field never lowers profileEarned() (monotonicity, review priority #4)', function (string $field, mixed $filledValue): void {
    $empty = [
        'display_name' => null,
        'country_code' => null,
        'region' => null,
        'date_of_birth' => null,
        'primary_language' => null,
        'categories' => null,
        'avatar_path' => null,
        // Pinned null for the same reason as the dataset test above — the
        // factory's random `bio` default would otherwise add noise that
        // could make the "before" and "after" draws disagree on something
        // other than the one field under test.
        'bio' => null,
        'accent' => null,
        'phone' => null,
        'whatsapp' => null,
        'address_street' => null,
        'address_postal_code' => null,
    ];

    $calc = new CompletenessScoreCalculator;
    $before = CreatorFactory::new()->createOne($empty);
    $baseline = $calc->profileEarned($before);

    $filled = $empty;
    $filled[$field] = $filledValue;
    $after = CreatorFactory::new()->createOne($filled);

    expect($calc->profileEarned($after))->toBeGreaterThan($baseline);
})->with([
    'display_name' => ['display_name', 'Someone'],
    'country_code' => ['country_code', 'IT'],
    'region' => ['region', 'Lazio'],
    'date_of_birth' => ['date_of_birth', '1994-03-17'],
    'primary_language' => ['primary_language', 'en'],
    'categories' => ['categories', ['music']],
    'avatar_path' => ['avatar_path', 'x'],
]);

it('AH-089 — production-parity regression pin: missing only DOB, otherwise fully complete, scores 97', function (): void {
    // Re-derives the exact number the AH-089 deploy-log rewrite quotes, so a
    // future weight edit that silently moves it is caught here rather than
    // discovered in production. kyc/tax/payout are build-time hidden
    // (WizardStep::WIZARD_HIDDEN_STEPS) regardless of this file's beforeEach
    // flag activations, so the applicable denominator is profile(25) +
    // social(15) + portfolio(10) + contract(15) = 65 — not 100.
    $creator = CreatorFactory::new()->createOne([
        'display_name' => 'Missing Birthday Only',
        'country_code' => 'IT',
        'region' => 'Lazio',
        'date_of_birth' => null,
        'primary_language' => 'en',
        'categories' => ['music'],
        'avatar_path' => 'x',
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

    // profile: 13×6/7 (≈11.14) + 12 optionals = 23.142857…
    // + social 15 + portfolio 10 + contract 15 = 63.142857… earned / 65
    // applicable × 100 = 97.14… → 97.
    expect($calc->score($creator))->toBe(97);
});
