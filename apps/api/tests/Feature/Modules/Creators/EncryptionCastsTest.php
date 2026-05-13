<?php

declare(strict_types=1);

use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorKycVerification;
use App\Modules\Creators\Models\CreatorSocialAccount;
use App\Modules\Creators\Models\CreatorTaxProfile;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Encryption-cast verification (#1 + #40)
|--------------------------------------------------------------------------
|
| Source-inspection regression test pinning the encryption cast list
| from docs/03-DATA-MODEL.md §23. Each test asserts that:
|
|   1) The Eloquent cast roundtrips (model property reads back as plaintext)
|   2) The DB column does NOT contain the plaintext (defense-in-depth check
|      that catches accidental cast removal — break-revert verified by
|      temporarily flipping the cast to a non-encrypted variant).
|
| If a column is added to or removed from the encryption list, this test
| (and the architecture test below) MUST be updated together with the
| model and the spec — the test prevents silent drift.
|
*/

it('creator_social_accounts.oauth_access_token is encrypted at rest', function (): void {
    $creator = Creator::factory()->createOne();
    $secret = 'test-oauth-access-token-plaintext-value';

    $row = CreatorSocialAccount::factory()->primary()->createOne([
        'creator_id' => $creator->id,
        'oauth_access_token' => $secret,
    ]);
    $row->refresh();

    // Read through the cast — should be plaintext.
    expect($row->oauth_access_token)->toBe($secret);

    // Read raw from DB — should NOT contain the plaintext.
    $raw = DB::table('creator_social_accounts')
        ->where('id', $row->id)
        ->value('oauth_access_token');

    expect($raw)->not->toBeNull()
        ->and((string) $raw)->not->toContain($secret);
});

it('creator_social_accounts.oauth_refresh_token is encrypted at rest', function (): void {
    $creator = Creator::factory()->createOne();
    $secret = 'test-oauth-refresh-token-plaintext-value';

    $row = CreatorSocialAccount::factory()->primary()->createOne([
        'creator_id' => $creator->id,
        'oauth_refresh_token' => $secret,
    ]);
    $row->refresh();

    expect($row->oauth_refresh_token)->toBe($secret);

    $raw = DB::table('creator_social_accounts')
        ->where('id', $row->id)
        ->value('oauth_refresh_token');

    expect((string) $raw)->not->toContain($secret);
});

it('creator_tax_profiles.legal_name is encrypted at rest', function (): void {
    $creator = Creator::factory()->createOne();
    $secret = 'PlaintextLegalName Smith Esq';

    $row = CreatorTaxProfile::factory()->createOne([
        'creator_id' => $creator->id,
        'legal_name' => $secret,
    ]);
    $row->refresh();

    expect($row->legal_name)->toBe($secret);

    $raw = DB::table('creator_tax_profiles')
        ->where('id', $row->id)
        ->value('legal_name');

    expect((string) $raw)->not->toContain($secret);
});

it('creator_tax_profiles.tax_id is encrypted at rest', function (): void {
    $creator = Creator::factory()->createOne();
    $secret = 'IT99999999999';

    $row = CreatorTaxProfile::factory()->createOne([
        'creator_id' => $creator->id,
        'tax_id' => $secret,
    ]);
    $row->refresh();

    expect($row->tax_id)->toBe($secret);

    $raw = DB::table('creator_tax_profiles')
        ->where('id', $row->id)
        ->value('tax_id');

    expect((string) $raw)->not->toContain($secret);
});

it('creator_tax_profiles.address is encrypted at rest', function (): void {
    $creator = Creator::factory()->createOne();
    $secret = ['line1' => '742 Evergreen Terrace', 'city' => 'Springfield'];

    $row = CreatorTaxProfile::factory()->createOne([
        'creator_id' => $creator->id,
        'address' => $secret,
    ]);
    $row->refresh();

    expect($row->address)->toBe($secret);

    $raw = DB::table('creator_tax_profiles')
        ->where('id', $row->id)
        ->value('address');

    expect((string) $raw)->not->toContain('Evergreen Terrace');
});

it('creator_kyc_verifications.decision_data is encrypted at rest', function (): void {
    $creator = Creator::factory()->createOne();
    $secret = ['outcome' => 'approved', 'document_number' => 'ABC123XYZ', 'confidence' => 0.99];

    $row = CreatorKycVerification::factory()->createOne([
        'creator_id' => $creator->id,
        'decision_data' => $secret,
    ]);
    $row->refresh();

    expect($row->decision_data)->toBe($secret);

    $raw = DB::table('creator_kyc_verifications')
        ->where('id', $row->id)
        ->value('decision_data');

    expect((string) $raw)->not->toContain('ABC123XYZ');
});

it('encryption cast list pinned to docs/03-DATA-MODEL.md §23 (architecture)', function (): void {
    // Source-inspection regression test (#1). If the data-model spec adds
    // or removes an encrypted column, this list AND the model casts MUST
    // both be updated. Drift between this test and the model casts is the
    // signal that the spec list and the code have diverged.
    $expected = [
        // Identity (Sprint 1 baseline — pinned for completeness; the cast
        // lives on User and a sibling test in Sprint 1 covers it. Re-pinned
        // here so this single test catches drift across the whole §23 list).
        ['model' => User::class, 'attribute' => 'two_factor_secret'],
        ['model' => User::class, 'attribute' => 'two_factor_recovery_codes'],
        // Creators (Sprint 3 Chunk 1).
        ['model' => CreatorSocialAccount::class, 'attribute' => 'oauth_access_token'],
        ['model' => CreatorSocialAccount::class, 'attribute' => 'oauth_refresh_token'],
        ['model' => CreatorTaxProfile::class, 'attribute' => 'legal_name'],
        ['model' => CreatorTaxProfile::class, 'attribute' => 'tax_id'],
        ['model' => CreatorTaxProfile::class, 'attribute' => 'address'],
        ['model' => CreatorKycVerification::class, 'attribute' => 'decision_data'],
    ];

    foreach ($expected as $row) {
        $instance = new $row['model'];
        $casts = $instance->getCasts();

        expect(array_key_exists($row['attribute'], $casts))
            ->toBeTrue("{$row['model']}::{$row['attribute']} should be in \$casts");

        $cast = $casts[$row['attribute']];
        expect((string) $cast)
            ->toMatch('/^encrypted(:.+)?$/', "{$row['model']}::{$row['attribute']} cast must start with 'encrypted'");
    }
});
