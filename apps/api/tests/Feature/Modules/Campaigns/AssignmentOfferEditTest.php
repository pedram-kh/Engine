<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\OfferLockReason;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Exceptions\AssignmentOfferEditException;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\AssignmentOfferEditor;
use App\Modules\Campaigns\Services\AssignmentOfferLock;
use App\Modules\Creators\Enums\ContractStatus;
use App\Modules\Creators\Models\Contract;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/OfferEditHelpers.php';

/**
 * AH-118 A5 — the agency edits an assignment's offer amount: the lock rule (all
 * five reason codes, over all 17 statuses), the endpoint, the flag 404, tenancy,
 * the role matrix and the no-op. The attach-vs-edit race lives in
 * CampaignAssignmentContractTest next to the attach it races.
 */

// ── the lock matrix ─────────────────────────────────────────────────────────

it('answers editable or the right reason for ALL 17 statuses', function (): void {
    $expected = [
        'invited' => null,
        'accepted' => null,
        'payment_held' => OfferLockReason::Payment,
        'payment_released' => OfferLockReason::Payment,
    ];

    expect(AssignmentStatus::cases())->toHaveCount(17);

    foreach (AssignmentStatus::cases() as $status) {
        ['assignment' => $assignment] = offerEditSetup($status);

        $want = array_key_exists($status->value, $expected) ? $expected[$status->value] : OfferLockReason::Status;

        expect(app(AssignmentOfferLock::class)->evaluate($assignment))->toBe($want, "status {$status->value}");
    }
});

it('locks with each of the five reason codes, in the documented order', function (): void {
    $lock = app(AssignmentOfferLock::class);

    // 1 feature_disabled — wins over everything else.
    ['assignment' => $a] = offerEditSetup(AssignmentStatus::Invited, flag: false);
    $a->forceFill(['payment_id' => 99, 'contract_id' => sendContractFor($a, ContractStatus::Signed)->id])->save();
    expect($lock->evaluate($a))->toBe(OfferLockReason::FeatureDisabled);

    // 2 payment — a payment_id on an otherwise editable row (seeded by hand: it is never set today) …
    ['assignment' => $b] = offerEditSetup(AssignmentStatus::Accepted);
    $b->forceFill(['payment_id' => 99])->save();
    expect($lock->evaluate($b))->toBe(OfferLockReason::Payment);

    // … and it outranks a signed contract.
    $b->forceFill(['contract_id' => sendContractFor($b, ContractStatus::Signed)->id])->save();
    expect($lock->evaluate($b))->toBe(OfferLockReason::Payment);

    // 3 contract_signed
    ['assignment' => $c] = offerEditSetup(AssignmentStatus::Accepted);
    $c->forceFill(['contract_id' => sendContractFor($c, ContractStatus::Signed)->id])->save();
    expect($lock->evaluate($c))->toBe(OfferLockReason::ContractSigned);

    // 4 contract_pending — a SENT contract, status still accepted (Q1) …
    ['assignment' => $d] = offerEditSetup(AssignmentStatus::Accepted);
    sendContractFor($d);
    expect($lock->evaluate($d))->toBe(OfferLockReason::ContractPending);

    // … but a contract in any other status does not pend.
    ['assignment' => $d2] = offerEditSetup(AssignmentStatus::Accepted);
    sendContractFor($d2, ContractStatus::Signed);
    expect($lock->evaluate($d2))->toBeNull();

    // 5 status
    ['assignment' => $e] = offerEditSetup(AssignmentStatus::Countered);
    expect($lock->evaluate($e))->toBe(OfferLockReason::Status);
});

it('locks a contract-less assignment at contracted (Q3), the payment-eligible states (Q2), and countered/declined (Q4)', function (AssignmentStatus $status): void {
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin] = offerEditSetup($status);

    $this->actingAs($admin)->patchJson(offerEditUrl($agency, $campaign, $assignment), ['agreed_fee_minor_units' => 200_00])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'assignment.offer_locked')
        ->assertJsonPath('errors.0.meta.reason', 'status');

    expect($assignment->fresh()?->agreed_fee_minor_units)->toBe(100_00);
})->with([
    'contracted (Q3)' => AssignmentStatus::Contracted,
    'live_verified (Q2)' => AssignmentStatus::LiveVerified,
    'manually_verified (Q2)' => AssignmentStatus::ManuallyVerified,
    'completed_on_approval (Q2)' => AssignmentStatus::CompletedOnApproval,
    'countered (Q4: re-invite)' => AssignmentStatus::Countered,
    'declined (Q4: re-offer)' => AssignmentStatus::Declined,
]);

// ── the endpoint ────────────────────────────────────────────────────────────

it('edits the amount on an invited and an accepted assignment, touching only the amount', function (AssignmentStatus $status): void {
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin] = offerEditSetup($status);

    $this->actingAs($admin)->patchJson(offerEditUrl($agency, $campaign, $assignment), ['agreed_fee_minor_units' => 250_00])
        ->assertOk()
        ->assertJsonPath('data.attributes.agreed_fee_minor_units', 250_00)
        ->assertJsonPath('data.attributes.agreed_fee_currency', 'EUR')
        ->assertJsonPath('data.attributes.status', $status->value)
        ->assertJsonPath('data.attributes.offer_edit_locked', false)
        ->assertJsonPath('data.attributes.offer_edit_lock_reason', null);

    $fresh = $assignment->fresh();
    expect($fresh?->agreed_fee_minor_units)->toBe(250_00)
        ->and($fresh?->agreed_fee_currency)->toBe('EUR')
        ->and($fresh?->fee_per)->toBe('video')
        ->and($fresh?->status)->toBe($status);
})->with([
    'invited' => AssignmentStatus::Invited,
    'accepted' => AssignmentStatus::Accepted,
]);

it('does not accept a currency (Q12)', function (): void {
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin] = offerEditSetup();

    $this->actingAs($admin)->patchJson(offerEditUrl($agency, $campaign, $assignment), [
        'agreed_fee_minor_units' => 250_00,
        'agreed_fee_currency' => 'USD',
    ])->assertStatus(422)->assertJsonPath('errors.0.meta.field', 'agreed_fee_currency');

    expect($assignment->fresh()?->agreed_fee_currency)->toBe('EUR')
        ->and($assignment->fresh()?->agreed_fee_minor_units)->toBe(100_00);
});

it('validates the amount: a positive integer', function (): void {
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin] = offerEditSetup();
    $url = offerEditUrl($agency, $campaign, $assignment);

    foreach ([[], ['agreed_fee_minor_units' => 0], ['agreed_fee_minor_units' => -5], ['agreed_fee_minor_units' => 'abc'], ['agreed_fee_minor_units' => 12.5]] as $body) {
        $this->actingAs($admin)->patchJson($url, $body)->assertStatus(422)
            ->assertJsonPath('errors.0.meta.field', 'agreed_fee_minor_units');
    }
});

it('refuses an unchanged amount with 422 offer_unchanged', function (): void {
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin] = offerEditSetup();

    $this->actingAs($admin)->patchJson(offerEditUrl($agency, $campaign, $assignment), ['agreed_fee_minor_units' => 100_00])
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'assignment.offer_unchanged');
});

it('is a 404 while the flag is OFF, before anything else is evaluated', function (): void {
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin] = offerEditSetup(flag: false);

    $this->actingAs($admin)->patchJson(offerEditUrl($agency, $campaign, $assignment), ['agreed_fee_minor_units' => 250_00])
        ->assertNotFound();

    expect($assignment->fresh()?->agreed_fee_minor_units)->toBe(100_00);
});

it('lets admin, manager and staff edit; a creator and another agency\'s admin get a 404', function (): void {
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment] = offerEditSetup();
    $url = offerEditUrl($agency, $campaign, $assignment);

    $amount = 100_00;
    foreach (['agencyAdmin', 'agencyManager', 'agencyStaff'] as $role) {
        $amount += 1_00;
        $this->actingAs(User::factory()->{$role}($agency)->createOne())
            ->patchJson($url, ['agreed_fee_minor_units' => $amount])->assertOk();
    }

    $before = $assignment->fresh()?->agreed_fee_minor_units;

    $this->actingAs(User::factory()->creator()->createOne())
        ->patchJson($url, ['agreed_fee_minor_units' => 999_00])->assertNotFound();

    $otherAgency = Agency::factory()->createOne();
    $this->actingAs(User::factory()->agencyAdmin($otherAgency)->createOne())
        ->patchJson($url, ['agreed_fee_minor_units' => 999_00])->assertNotFound();

    expect($assignment->fresh()?->agreed_fee_minor_units)->toBe($before);
});

it('is a 404 across tenants and across campaigns', function (): void {
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin] = offerEditSetup();

    // Another campaign of the SAME agency.
    $other = Campaign::factory()->createOne(['agency_id' => $agency->id, 'brand_id' => $campaign->brand_id]);
    $this->actingAs($admin)->patchJson(offerEditUrl($agency, $other, $assignment), ['agreed_fee_minor_units' => 250_00])
        ->assertNotFound();

    // Another agency's campaign under this agency's URL.
    ['campaign' => $foreignCampaign, 'assignment' => $foreignAssignment] = offerEditSetup();
    $this->actingAs($admin)->patchJson(offerEditUrl($agency, $foreignCampaign, $foreignAssignment), ['agreed_fee_minor_units' => 250_00])
        ->assertNotFound();

    expect($foreignAssignment->fresh()?->agreed_fee_minor_units)->toBe(100_00)
        ->and($assignment->fresh()?->agreed_fee_minor_units)->toBe(100_00);
});

// ── payloads ────────────────────────────────────────────────────────────────

it('carries offer_edit_locked and its reason on the assignment list and the review drawer', function (): void {
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin] = offerEditSetup(AssignmentStatus::Accepted);
    sendContractFor($assignment);

    $list = $this->actingAs($admin)->getJson("/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments")->assertOk();
    /** @var list<array{id: string, attributes: array<string, mixed>}> $rows */
    $rows = $list->json('data');
    $row = array_values(array_filter($rows, static fn (array $r): bool => $r['id'] === $assignment->ulid))[0];
    expect($row['attributes']['offer_edit_locked'])->toBeTrue()
        ->and($row['attributes']['offer_edit_lock_reason'])->toBe('contract_pending');

    $this->actingAs($admin)->getJson("/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments/{$assignment->ulid}")
        ->assertOk()
        ->assertJsonPath('data.attributes.offer_edit_locked', true)
        ->assertJsonPath('data.attributes.offer_edit_lock_reason', 'contract_pending');
});

it('reports the flag as a lock reason in the payload while OFF', function (): void {
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin] = offerEditSetup(flag: false);

    $list = $this->actingAs($admin)->getJson("/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments")->assertOk();
    expect($list->json('data.0.attributes.offer_edit_lock_reason'))->toBe('feature_disabled');
});

// ── the editor is not a state-machine edge ──────────────────────────────────

it('dispatches no AssignmentTransitioned and leaves the status alone', function (): void {
    Event::fake([AssignmentTransitioned::class]);
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin] = offerEditSetup(AssignmentStatus::Accepted);

    $this->actingAs($admin)->patchJson(offerEditUrl($agency, $campaign, $assignment), ['agreed_fee_minor_units' => 250_00])->assertOk();

    Event::assertNotDispatched(AssignmentTransitioned::class);
    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::Accepted);
});

// ── the in-transaction re-check (the race, made deterministic) ──────────────

it('re-checks the lock against the committed row, not the caller\'s stale model', function (): void {
    ['assignment' => $stale, 'admin' => $admin] = offerEditSetup(AssignmentStatus::Accepted);

    // Another request commits a sent contract AFTER this one loaded its model.
    sendContractFor(CampaignAssignment::query()->findOrFail($stale->id));
    expect($stale->relationLoaded('sentContract'))->toBeFalse();

    try {
        app(AssignmentOfferEditor::class)->edit($stale, 250_00, $admin);
        $this->fail('The edit should have been refused.');
    } catch (AssignmentOfferEditException $e) {
        expect($e->errorCode)->toBe('assignment.offer_locked')
            ->and($e->reason)->toBe(OfferLockReason::ContractPending);
    }

    expect(CampaignAssignment::query()->findOrFail($stale->id)->agreed_fee_minor_units)->toBe(100_00);
});

it('re-checks the status against the committed row too (a creator counter in between)', function (): void {
    ['assignment' => $stale, 'admin' => $admin] = offerEditSetup(AssignmentStatus::Invited);

    CampaignAssignment::query()->whereKey($stale->id)->update(['status' => AssignmentStatus::Countered]);
    expect($stale->status)->toBe(AssignmentStatus::Invited);

    expect(fn () => app(AssignmentOfferEditor::class)->edit($stale, 250_00, $admin))
        ->toThrow(AssignmentOfferEditException::class);
});
