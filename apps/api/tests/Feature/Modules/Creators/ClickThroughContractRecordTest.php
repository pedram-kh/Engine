<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\ContractKind;
use App\Modules\Creators\Enums\ContractStatus;
use App\Modules\Creators\Enums\WizardStep;
use App\Modules\Creators\Features\ContractSigningEnabled;
use App\Modules\Creators\Models\Contract;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\CompletenessScoreCalculator;
use App\Modules\Creators\Services\ContractTermsRenderer;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sprint 4 Chunk 4 — structured contract-acceptance record (D-c4-1..5)
|--------------------------------------------------------------------------
|
| Defense-in-depth coverage (§5.17). The click-through accept must now
| produce a versioned + timestamped + attributed `contracts` row, unified
| with the future vendor path. Break-revert anchors (§5.35) are called out
| inline on the load-bearing claims.
|
*/

beforeEach(function (): void {
    cache()->store('array')->flush();
    // Click-through is the flag-OFF path.
    Feature::deactivate(ContractSigningEnabled::NAME);
});

function makeContractCreator(): array
{
    $user = User::factory()->createOne();
    $creator = CreatorFactory::new()->bootstrap()->createOne(['user_id' => $user->id]);

    return [$user, $creator];
}

// ---------------------------------------------------------------------------
// D-c4-2 — accept creates a correct, versioned contracts row
// ---------------------------------------------------------------------------

it('creates a contracts row with the correct version, signer, timestamp, status, and provider', function (): void {
    [$user, $creator] = makeContractCreator();

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/wizard/contract/click-through-accept')
        ->assertOk();

    $contract = Contract::query()->firstOrFail();

    // Break-revert (§5.35): drop the version write in the service and this
    // assertion fails — the version-present guarantee is load-bearing.
    expect($contract->version)->toBe(ContractTermsRenderer::currentVersionNumber());
    expect($contract->version)->toBe(1);

    expect($contract->kind)->toBe(ContractKind::MasterUniversal);
    expect($contract->status)->toBe(ContractStatus::Signed);
    expect($contract->signature_provider)->toBe(Contract::PROVIDER_INTERNAL);
    expect($contract->subject_type)->toBe(Contract::SUBJECT_CREATOR);
    expect($contract->subject_id)->toBe($creator->id);
    expect($contract->signed_by_creator_id)->toBe($creator->id);
    expect($contract->signed_at)->not->toBeNull();
    expect($contract->created_by_user_id)->toBe($creator->user_id);
});

it('snapshots the agreed title and RAW markdown source (not rendered HTML) onto the row', function (): void {
    [$user] = makeContractCreator();

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/wizard/contract/click-through-accept')
        ->assertOk();

    $contract = Contract::query()->firstOrFail();
    $source = app(ContractTermsRenderer::class)->source();

    expect($contract->title)->toBe($source['title']);
    expect($contract->body_markdown)->toBe($source['markdown']);
    // Raw markdown, never the rendered HTML.
    expect($contract->body_markdown)->toContain('# Engine C');
    expect($contract->body_markdown)->not->toContain('<h1>');
});

it('records method + ip + user_agent + version + accepted_at in signed_signature_data', function (): void {
    [$user] = makeContractCreator();

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/wizard/contract/click-through-accept')
        ->assertOk();

    $data = Contract::query()->firstOrFail()->signed_signature_data;

    expect($data)->toBeArray();
    expect(data_get($data, 'method'))->toBe(Contract::METHOD_CLICK_THROUGH);
    expect(data_get($data, 'version'))->toBe(ContractTermsRenderer::CURRENT_VERSION); // precise '1.0'
    expect($data)->toHaveKeys(['ip', 'user_agent', 'accepted_at']);
    expect(data_get($data, 'ip'))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// D-c4-2 — the FK is set and points at the new row
// ---------------------------------------------------------------------------

it('sets creators.signed_master_contract_id to the new contracts row id', function (): void {
    [$user, $creator] = makeContractCreator();

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/wizard/contract/click-through-accept')
        ->assertOk();

    $contract = Contract::query()->firstOrFail();
    $creator->refresh();

    expect($creator->signed_master_contract_id)->toBe($contract->id);
    // And the Eloquent relation resolves to the same row.
    expect($creator->masterContract->is($contract))->toBeTrue();
});

// ---------------------------------------------------------------------------
// D-c4-3 — step-8 satisfaction keys off signed_master_contract_id
// ---------------------------------------------------------------------------

it('step-8 satisfaction keys off signed_master_contract_id, not the legacy timestamp', function (): void {
    // Force the strict (flag-ON) path so the flag-OFF clause can't mask
    // the column check.
    Feature::activate(ContractSigningEnabled::NAME);
    $calculator = app(CompletenessScoreCalculator::class);

    [, $withFk] = makeContractCreator();
    $withFk->forceFill([
        'signed_master_contract_id' => Contract::factory()->create()->id,
        'click_through_accepted_at' => null,
    ])->save();

    // Break-revert (§5.35): FK set + NO legacy timestamp still passes.
    expect($calculator->stepCompletion($withFk->fresh())[WizardStep::Contract->value])->toBeTrue();

    [, $withNeither] = makeContractCreator();
    expect($calculator->stepCompletion($withNeither->fresh())[WizardStep::Contract->value])->toBeFalse();
});

// ---------------------------------------------------------------------------
// Idempotency (#6) — re-accept creates no duplicate row
// ---------------------------------------------------------------------------

it('is idempotent — a second accept creates no duplicate contracts row', function (): void {
    [$user] = makeContractCreator();

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/wizard/contract/click-through-accept')
        ->assertOk();
    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/wizard/contract/click-through-accept')
        ->assertOk();

    expect(Contract::query()->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Continuity (closes the inventory's point 6)
// ---------------------------------------------------------------------------

it('makes acceptances retrievable WITH version from one place (contracts)', function (): void {
    [$userA] = makeContractCreator();
    [$userB] = makeContractCreator();

    $this->actingAs($userA)
        ->postJson('/api/v1/creators/me/wizard/contract/click-through-accept')
        ->assertOk();
    $this->actingAs($userB)
        ->postJson('/api/v1/creators/me/wizard/contract/click-through-accept')
        ->assertOk();

    // The whole point of the chunk: one query yields every acceptance,
    // each carrying its version — no bare-column / vendor-row split.
    $acceptances = Contract::query()
        ->where('subject_type', Contract::SUBJECT_CREATOR)
        ->where('status', ContractStatus::Signed->value)
        ->get();

    expect($acceptances)->toHaveCount(2);
    $acceptances->each(function (Contract $c): void {
        expect($c->version)->toBeInt();
        expect($c->version)->toBe(1);
    });
});

// ---------------------------------------------------------------------------
// D-c4-5 — signed_signature_data is NOT creator-facing
// ---------------------------------------------------------------------------

it('never exposes signed_signature_data (IP/UA) on the creator-facing resource', function (): void {
    [$user] = makeContractCreator();

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/wizard/contract/click-through-accept')
        ->assertOk();

    $body = (string) $this->actingAs($user)->getJson('/api/v1/creators/me')->getContent();

    expect($body)->not->toContain('signed_signature_data');
    expect($body)->not->toContain('user_agent');
    // The captured IP must not leak through any creator-facing field.
    expect($body)->not->toContain('127.0.0.1');
    // The denormalised acceptance timestamp IS still surfaced (D-c4-3).
    expect($body)->toContain('click_through_accepted_at');
});
