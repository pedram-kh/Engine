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
    expect($contract->body_markdown)->toContain('# Catalyst Creator Terms and Conditions');
    expect($contract->body_markdown)->not->toContain('<h1>');

    // v1.1 content pins (AH-049): the swap ADDED clause 2.4 (revision rounds)
    // and clause 4.3 (30-day payment) and EXPANDED clause 7.3 (portfolio
    // consent). I3 proved the pre-swap tests were content-blind — pin the
    // distinctive new wording so a future content drop reds here. Verified by
    // break-revert (delete clause 2.4 from the source → this fails).
    expect($contract->body_markdown)->toContain('**2.4**');
    expect($contract->body_markdown)->toContain('up to three (3)');
    expect($contract->body_markdown)->toContain('**4.3**');
    expect($contract->body_markdown)->toContain('within 30');
    expect($contract->body_markdown)->toContain('portfolio purposes');
});

it('records method + ip + user_agent + version + accepted_at in signed_signature_data', function (): void {
    [$user] = makeContractCreator();

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/wizard/contract/click-through-accept')
        ->assertOk();

    $data = Contract::query()->firstOrFail()->signed_signature_data;

    expect($data)->toBeArray();
    expect(data_get($data, 'method'))->toBe(Contract::METHOD_CLICK_THROUGH);
    // The precise (non-lossy) version string a NEW acceptance snapshots.
    // AH-049 bumped it 1.0 → 1.1; existing rows keep their own '1.0' string
    // (pinned in ClickThroughContractBackfillTest). Literal AND constant so
    // the bump is guarded even if the constant is reverted by mistake.
    expect(data_get($data, 'version'))->toBe(ContractTermsRenderer::CURRENT_VERSION);
    expect(data_get($data, 'version'))->toBe('1.1');
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
// §5.34 — snapshot immutability across the content swap + version bump (AH-049)
// ---------------------------------------------------------------------------

it('leaves a pre-swap (v1.0) contract snapshot byte-untouched after the source + version bump', function (): void {
    [$user, $creator] = makeContractCreator();

    // A signee who accepted BEFORE this swap: their row carries the OLD text
    // and the OLD precise version string. This is the row the production-data
    // safety standard (§5.40) forbids us to ever mutate — the DB snapshot,
    // not the source markdown, is the authority for what they agreed to.
    $oldBody = "# Master Creator Agreement\n\nEngine C Ltd — governed by the laws of Ireland.";
    $preSwap = Contract::factory()->create([
        'subject_type' => Contract::SUBJECT_CREATOR,
        'subject_id' => $creator->id,
        'version' => 1,
        'title' => 'Master Creator Agreement',
        'body_markdown' => $oldBody,
        'signed_by_creator_id' => $creator->id,
        'signed_signature_data' => [
            'method' => Contract::METHOD_CLICK_THROUGH,
            'version' => '1.0',
            'ip' => '203.0.113.10',
            'user_agent' => 'PHPUnit',
            'accepted_at' => now()->subYear()->toIso8601String(),
        ],
    ]);
    $creator->forceFill([
        'signed_master_contract_id' => $preSwap->id,
        'click_through_accepted_at' => now()->subYear(),
    ])->save();

    // Sanity: the source really is bumped to v1.1 and carries the new clause.
    expect(ContractTermsRenderer::CURRENT_VERSION)->toBe('1.1');
    expect(app(ContractTermsRenderer::class)->source()['markdown'])->toContain('**2.4**');

    // Re-entering the accept path is an idempotent no-op (the guard keys off
    // the denormalised timestamp) — it must NOT re-snapshot the new v1.1 text
    // onto the existing row or mint a duplicate.
    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/wizard/contract/click-through-accept')
        ->assertOk();

    $preSwap->refresh();
    expect(Contract::query()->count())->toBe(1);
    // Byte-untouched: old body, old label — the swap changed neither.
    expect($preSwap->body_markdown)->toBe($oldBody);
    expect($preSwap->body_markdown)->not->toContain('**2.4**');
    expect($preSwap->version)->toBe(1);
    expect(data_get($preSwap->signed_signature_data, 'version'))->toBe('1.0');
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
