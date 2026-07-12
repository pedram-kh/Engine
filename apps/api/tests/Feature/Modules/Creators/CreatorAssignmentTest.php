<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 8 Chunk 2 — the CREATOR half of the assignment lifecycle (D-9). Mirrors
 * the 6.6b connection-request contract:
 *
 *   GET    /api/v1/creators/me/assignments
 *   POST   /api/v1/creators/me/assignments/{assignment}/accept
 *   POST   /api/v1/creators/me/assignments/{assignment}/decline
 *   POST   /api/v1/creators/me/assignments/{assignment}/counter
 *
 * Pins: creator-self scoping (a creator can't act on another's assignment —
 * non-owned ULID 404), fail-closed unless `invited`, the machine owns the flip,
 * counter records countered_fee (not agreed_fee).
 */

/**
 * @return array{0: User, 1: Creator}
 */
function creatorUser(array $attributes = []): array
{
    $user = User::factory()->create($attributes);
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

    return [$user, $creator];
}

function invitedAssignmentFor(Creator $creator, array $overrides = []): CampaignAssignment
{
    $campaign = Campaign::factory()->create(['budget_currency' => 'EUR']);

    return CampaignAssignment::factory()->status(AssignmentStatus::Invited)->create(array_merge([
        'campaign_id' => $campaign->id,
        'creator_id' => $creator->id,
        'agreed_fee_minor_units' => 500_000,
        'agreed_fee_currency' => 'EUR',
    ], $overrides));
}

function assignmentUrl(CampaignAssignment $assignment, string $verb): string
{
    return "/api/v1/creators/me/assignments/{$assignment->ulid}/{$verb}";
}

// The repo's `fresh() ?? $self` reload idiom — fresh() is typed ?static, so the
// non-null reload keeps Larastan level-8 happy on property reads (guarded
// because Pest loads every test file into one shared function scope).
if (! function_exists('reloadAssignment')) {
    function reloadAssignment(CampaignAssignment $assignment): CampaignAssignment
    {
        return $assignment->fresh() ?? $assignment;
    }
}

// ── List ─────────────────────────────────────────────────────────────────────

it('returns 401 when unauthenticated', function (): void {
    expect($this->getJson('/api/v1/creators/me/assignments')->status())->toBe(401);
});

it('lists the creator\'s OWN assignments across all agencies (scope bypass)', function (): void {
    [$user, $creator] = creatorUser();
    invitedAssignmentFor($creator);
    invitedAssignmentFor($creator);

    // Another creator's assignment must NOT appear.
    $other = CreatorFactory::new()->createOne();
    invitedAssignmentFor($other);

    $this->actingAs($user)
        ->getJson('/api/v1/creators/me/assignments')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('emits the invite-offer context (fee_per, offer_description, offer_attachment) on the list row', function (): void {
    [$user, $creator] = creatorUser();
    invitedAssignmentFor($creator, [
        'fee_per' => 'per script',
        'offer_description' => 'One 60s UGC video.',
        'offer_attachment_path' => 'agencies/01A/campaigns/01B/offer-attachments/01C.pdf',
        'offer_attachment_name' => 'brief.pdf',
        'offer_attachment_mime' => 'application/pdf',
        'offer_attachment_size_bytes' => 2048,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/creators/me/assignments')
        ->assertOk();

    // Local test disk cannot sign, so `url` is null — the metadata block is
    // the assertable surface (the CampaignAssignmentInviteTest counterpart).
    expect($response->json('data.0.attributes.fee_per'))->toBe('per script')
        ->and($response->json('data.0.attributes.offer_description'))->toBe('One 60s UGC video.')
        ->and($response->json('data.0.attributes.offer_attachment.name'))->toBe('brief.pdf')
        ->and($response->json('data.0.attributes.offer_attachment.size_bytes'))->toBe(2048);
});

// ── Accept / decline (machine-driven) ────────────────────────────────────────

it('accepts an invited assignment (invited → accepted) via the state machine', function (): void {
    [$user, $creator] = creatorUser();
    $assignment = invitedAssignmentFor($creator);

    $this->actingAs($user)
        ->postJson(assignmentUrl($assignment, 'accept'))
        ->assertOk()
        ->assertJsonPath('meta.code', 'assignment.accepted')
        ->assertJsonPath('data.attributes.status', 'accepted');

    expect(reloadAssignment($assignment)->status)->toBe(AssignmentStatus::Accepted);
    expect(AuditLog::query()->where('action', 'assignment.accepted')->where('subject_id', $assignment->id)->exists())->toBeTrue();
});

it('declines an invited assignment (invited → declined)', function (): void {
    [$user, $creator] = creatorUser();
    $assignment = invitedAssignmentFor($creator);

    $this->actingAs($user)
        ->postJson(assignmentUrl($assignment, 'decline'))
        ->assertOk()
        ->assertJsonPath('meta.code', 'assignment.declined');

    expect(reloadAssignment($assignment)->status)->toBe(AssignmentStatus::Declined);
});

// ── Counter (records countered_fee, NOT agreed_fee — D-7/D-8) ────────────────

it('counters an invited assignment recording countered_fee WITHOUT touching agreed_fee', function (): void {
    [$user, $creator] = creatorUser();
    $assignment = invitedAssignmentFor($creator);

    $this->actingAs($user)
        ->postJson(assignmentUrl($assignment, 'counter'), [
            'countered_fee_minor_units' => 750_000,
            'countered_fee_currency' => 'EUR',
        ])
        ->assertOk()
        ->assertJsonPath('meta.code', 'assignment.countered')
        ->assertJsonPath('data.attributes.status', 'countered');

    $fresh = reloadAssignment($assignment);
    expect($fresh->status)->toBe(AssignmentStatus::Countered)
        ->and($fresh->countered_fee_minor_units)->toBe(750_000)
        ->and($fresh->agreed_fee_minor_units)->toBe(500_000); // untouched
});

it('rejects a counter whose currency does not match the campaign currency (422)', function (): void {
    [$user, $creator] = creatorUser();
    $assignment = invitedAssignmentFor($creator);

    $this->actingAs($user)
        ->postJson(assignmentUrl($assignment, 'counter'), [
            'countered_fee_minor_units' => 750_000,
            'countered_fee_currency' => 'USD',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.source.pointer', '/data/attributes/countered_fee_currency');
});

// ── Creator-self scoping (non-owned ULID 404) ────────────────────────────────

it('404s when acting on another creator\'s assignment (structural owner-only guard)', function (): void {
    [$user] = creatorUser();
    $other = CreatorFactory::new()->createOne();
    $foreign = invitedAssignmentFor($other);

    $this->actingAs($user)
        ->postJson(assignmentUrl($foreign, 'accept'))
        ->assertNotFound();

    // The foreign row is untouched.
    expect(reloadAssignment($foreign)->status)->toBe(AssignmentStatus::Invited);
});

// ── Fail-closed unless invited ───────────────────────────────────────────────

it('fails closed — a non-invited assignment cannot be accepted/declined/countered (422 assignment.not_invited)', function (): void {
    [$user, $creator] = creatorUser();
    $accepted = invitedAssignmentFor($creator, ['status' => AssignmentStatus::Accepted]);

    foreach (['accept', 'decline', 'counter'] as $verb) {
        $payload = $verb === 'counter'
            ? ['countered_fee_minor_units' => 750_000, 'countered_fee_currency' => 'EUR']
            : [];

        $this->actingAs($user)
            ->postJson(assignmentUrl($accepted, $verb), $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'assignment.not_invited');
    }

    expect(reloadAssignment($accepted)->status)->toBe(AssignmentStatus::Accepted);
});
