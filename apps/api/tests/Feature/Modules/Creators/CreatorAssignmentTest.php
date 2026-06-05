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

// ── Accept / decline (machine-driven) ────────────────────────────────────────

it('accepts an invited assignment (invited → accepted) via the state machine', function (): void {
    [$user, $creator] = creatorUser();
    $assignment = invitedAssignmentFor($creator);

    $this->actingAs($user)
        ->postJson(assignmentUrl($assignment, 'accept'))
        ->assertOk()
        ->assertJsonPath('meta.code', 'assignment.accepted')
        ->assertJsonPath('data.attributes.status', 'accepted');

    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Accepted);
    expect(AuditLog::query()->where('action', 'assignment.accepted')->where('subject_id', $assignment->id)->exists())->toBeTrue();
});

it('declines an invited assignment (invited → declined)', function (): void {
    [$user, $creator] = creatorUser();
    $assignment = invitedAssignmentFor($creator);

    $this->actingAs($user)
        ->postJson(assignmentUrl($assignment, 'decline'))
        ->assertOk()
        ->assertJsonPath('meta.code', 'assignment.declined');

    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Declined);
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

    $fresh = $assignment->fresh();
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
    expect($foreign->fresh()->status)->toBe(AssignmentStatus::Invited);
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

    expect($accepted->fresh()->status)->toBe(AssignmentStatus::Accepted);
});
