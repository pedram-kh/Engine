<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Mail\ApplicationAcceptedMail;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Features\ApplicationNotificationsEnabled;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-058 (chunk 4, D3b) — the DIRECT-INVITE path's one new behaviour, and the
 * §5.34 proof that everything else about it is unchanged.
 *
 * The chunk touches one live path: `POST …/assignments`. Two claims need pinning
 * from the outside, and they pull in opposite directions —
 *
 *   1. For a pair with NO application (every pair that exists in production
 *      today), the invite is BYTE-IDENTICAL to before: same row, same audit row,
 *      same event, same board card, same 201. Asserted field-by-field, because
 *      "it still works" is what a regression looks like from the inside.
 *   2. For a pair WITH a pending application, the application settles as
 *      `accepted` in the same transaction and the creator is told.
 *
 * Claim 2 is what makes claim 1 non-vacuous: if the hook were never wired at
 * all, claim 1 would pass just as happily. Removing the
 * `settlePendingApplication()` call reds every test in the second section and
 * leaves the first section green — which is the break-revert, run by hand, and
 * the reason both sections live in one file.
 */

/**
 * @return array{agency: Agency, brand: Brand, campaign: Campaign, creator: Creator, creatorUser: User, admin: User}
 */
function convergenceSetup(): array
{
    $agency = Agency::factory()->createOne(['name' => 'Bright Harbour']);
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->listed()->createOne([
        'agency_id' => $agency->id,
        'brand_id' => $brand->id,
        'name' => 'Autumn UGC push',
        'budget_currency' => 'EUR',
    ]);

    $creatorUser = User::factory()->creator()->createOne(['name' => 'Maria']);
    $creator = CreatorFactory::new()->approved()->createOne([
        'user_id' => $creatorUser->id,
        'display_name' => 'Maria Lopez',
    ]);

    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
    ]);

    return [
        'agency' => $agency,
        'brand' => $brand,
        'campaign' => $campaign,
        'creator' => $creator,
        'creatorUser' => $creatorUser,
        'admin' => User::factory()->agencyAdmin($agency)->createOne(),
    ];
}

function convergenceInviteUrl(array $s): string
{
    return "/api/v1/agencies/{$s['agency']->ulid}/campaigns/{$s['campaign']->ulid}/assignments";
}

/**
 * @return array<string, mixed>
 */
function convergencePayload(Creator $creator, array $overrides = []): array
{
    return array_merge([
        'creator_id' => $creator->ulid,
        'agreed_fee_minor_units' => 500_000,
        'agreed_fee_currency' => 'EUR',
    ], $overrides);
}

function pendingApplicationFor(array $s, ?Creator $creator = null): CampaignApplication
{
    return CampaignApplication::factory()->createOne([
        'campaign_id' => $s['campaign']->id,
        'agency_id' => $s['agency']->id,
        'creator_id' => ($creator ?? $s['creator'])->id,
    ]);
}

// ── §5.34 — a pair with NO application is byte-identical to today ────────────

it('§5.34: invites a pair with NO application exactly as before — field by field', function (): void {
    Mail::fake();
    Feature::activate(ApplicationNotificationsEnabled::NAME);
    $s = convergenceSetup();

    $this->actingAs($s['admin'])
        ->postJson(convergenceInviteUrl($s), convergencePayload($s['creator'], [
            'fee_per' => 'per video',
            'offer_description' => 'Two 30s verticals.',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.attributes.status', 'invited');

    $assignment = CampaignAssignment::query()->sole();

    // The created row, field by field — the invite's whole output contract.
    expect($assignment->agency_id)->toBe($s['agency']->id)
        ->and($assignment->campaign_id)->toBe($s['campaign']->id)
        ->and($assignment->creator_id)->toBe($s['creator']->id)
        ->and($assignment->status)->toBe(AssignmentStatus::Invited)
        ->and($assignment->agreed_fee_minor_units)->toBe(500_000)
        ->and($assignment->agreed_fee_currency)->toBe('EUR')
        ->and($assignment->fee_per)->toBe('per video')
        ->and($assignment->offer_description)->toBe('Two 30s verticals.')
        ->and($assignment->invited_by_user_id)->toBe($s['admin']->id)
        ->and($assignment->invited_at)->not->toBeNull()
        ->and($assignment->responded_at)->toBeNull();

    // Its audit row, its board card, its one event — the three downstream facts
    // that a transaction wrapped around this path could have broken.
    expect(AuditLog::query()->where('action', AuditAction::AssignmentInvited->value)->count())->toBe(1)
        ->and(BoardCard::query()->where('assignment_id', $assignment->id)->exists())->toBeTrue();

    // …and NOTHING from the applications half of the world fired: no application
    // row exists, so no flip, no audit row, no notification, no mail — with the
    // flag deliberately ARMED so a silent flag-OFF cannot be why.
    expect(CampaignApplication::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::CampaignApplicationAccepted->value)->count())->toBe(0)
        ->and(Notification::query()->where('type', NotificationType::CampaignApplicationAccepted->value)->count())->toBe(0);

    Mail::assertNotQueued(ApplicationAcceptedMail::class);
});

it('§5.34: an application from ANOTHER creator on the campaign is left alone', function (): void {
    Mail::fake();
    Feature::activate(ApplicationNotificationsEnabled::NAME);
    $s = convergenceSetup();

    $otherApplicant = CreatorFactory::new()->approved()->createOne();
    $otherApplication = pendingApplicationFor($s, $otherApplicant);

    $this->actingAs($s['admin'])
        ->postJson(convergenceInviteUrl($s), convergencePayload($s['creator']))
        ->assertCreated();

    // The hook is PAIR-scoped, not campaign-scoped. Inviting one creator must
    // never answer a different creator's application.
    expect($otherApplication->fresh()?->status)->toBe(CampaignApplicationStatus::Pending)
        ->and($otherApplication->fresh()?->responded_at)->toBeNull();

    Mail::assertNotQueued(ApplicationAcceptedMail::class);
});

// ── D3b — the pair WITH a pending application, on both offer branches ────────

it('D3b · CREATE branch: a pending application for the invited pair settles as accepted', function (): void {
    Mail::fake();
    Feature::activate(ApplicationNotificationsEnabled::NAME);
    $s = convergenceSetup();
    $application = pendingApplicationFor($s);

    $this->actingAs($s['admin'])
        ->postJson(convergenceInviteUrl($s), convergencePayload($s['creator']))
        ->assertCreated();

    $assignment = CampaignAssignment::query()->sole();
    $settled = $application->fresh();

    // The agency did the thing the application was asking for, so the
    // application is answered — not left pending forever, counted in a badge
    // nobody can clear.
    expect($settled?->status)->toBe(CampaignApplicationStatus::Accepted)
        ->and($settled?->responded_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::CampaignApplicationAccepted->value)->count())->toBe(1);

    $notification = Notification::query()
        ->where('type', NotificationType::CampaignApplicationAccepted->value)
        ->sole();

    // The notice points at the invitation the invite created, so the creator
    // lands on the offer that needs an answer.
    expect($notification->recipient_user_id)->toBe($s['creatorUser']->id)
        ->and($notification->data['assignment_ulid'])->toBe($assignment->ulid);

    Mail::assertQueued(ApplicationAcceptedMail::class);
});

it('D3b · DECLINED RE-OFFER branch: the AH-035 edge settles the application too', function (): void {
    Mail::fake();
    Feature::activate(ApplicationNotificationsEnabled::NAME);
    $s = convergenceSetup();

    $declined = CampaignAssignment::factory()->createOne([
        'agency_id' => $s['agency']->id,
        'campaign_id' => $s['campaign']->id,
        'creator_id' => $s['creator']->id,
        'status' => AssignmentStatus::Declined,
    ]);

    // The sequence a create-only hook would miss: declined, then applied later,
    // then re-offered. This creator's application MUST settle.
    $application = pendingApplicationFor($s);

    $this->actingAs($s['admin'])
        ->postJson(convergenceInviteUrl($s), convergencePayload($s['creator'], [
            'agreed_fee_minor_units' => 700_000,
        ]))
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'invited');

    expect($application->fresh()?->status)->toBe(CampaignApplicationStatus::Accepted)
        // The same row was re-offered (AH-035), so the notice links to it.
        ->and(CampaignAssignment::query()->count())->toBe(1)
        ->and($declined->fresh()?->agreed_fee_minor_units)->toBe(700_000)
        ->and($declined->fresh()?->status)->toBe(AssignmentStatus::Invited);

    $notification = Notification::query()
        ->where('type', NotificationType::CampaignApplicationAccepted->value)
        ->sole();

    expect($notification->data['assignment_ulid'])->toBe($declined->ulid);

    Mail::assertQueued(ApplicationAcceptedMail::class);
});

it('D3b: an ALREADY-TERMINAL application is not re-answered by an invite', function (): void {
    Mail::fake();
    Feature::activate(ApplicationNotificationsEnabled::NAME);
    $s = convergenceSetup();

    $rejected = pendingApplicationFor($s);
    $respondedAt = now()->subWeek();
    $rejected->forceFill([
        'status' => CampaignApplicationStatus::Rejected,
        'responded_at' => $respondedAt,
    ])->save();

    $this->actingAs($s['admin'])
        ->postJson(convergenceInviteUrl($s), convergencePayload($s['creator']))
        ->assertCreated();

    // An agency may reject an application and invite the same creator later —
    // that is a legitimate change of mind, and it must not rewrite the history of
    // the earlier answer.
    expect($rejected->fresh()?->status)->toBe(CampaignApplicationStatus::Rejected)
        ->and($rejected->fresh()?->responded_at?->toIso8601String())->toBe($respondedAt->toIso8601String())
        ->and(Notification::query()->where('type', NotificationType::CampaignApplicationAccepted->value)->count())->toBe(0);

    Mail::assertNotQueued(ApplicationAcceptedMail::class);
});

it('D3b: the idempotent no-op branch makes no offer, so it settles nothing', function (): void {
    Mail::fake();
    Feature::activate(ApplicationNotificationsEnabled::NAME);
    $s = convergenceSetup();

    CampaignAssignment::factory()->createOne([
        'agency_id' => $s['agency']->id,
        'campaign_id' => $s['campaign']->id,
        'creator_id' => $s['creator']->id,
        'status' => AssignmentStatus::Accepted,
    ]);

    $application = pendingApplicationFor($s);

    $this->actingAs($s['admin'])
        ->postJson(convergenceInviteUrl($s), convergencePayload($s['creator']))
        ->assertOk();

    // The hook hangs off the two branches that put an OFFER in front of a
    // creator, and this branch is the pre-existing no-op: the pair is already
    // engaged, nothing new was offered, so nothing has answered the application.
    // The accept endpoint refuses it with `application.already_engaged`, naming
    // the engagement — which is the honest surface for an operator to see.
    expect($application->fresh()?->status)->toBe(CampaignApplicationStatus::Pending);

    Mail::assertNotQueued(ApplicationAcceptedMail::class);
});

// ── The hook's own rollback + the bulk loop ──────────────────────────────────

it('ROLLBACK: a failure in the invite transaction leaves the application PENDING and nothing mailed', function (): void {
    Mail::fake();
    Feature::activate(ApplicationNotificationsEnabled::NAME);
    $s = convergenceSetup();
    $application = pendingApplicationFor($s);

    Event::listen(AssignmentTransitioned::class, function (): void {
        throw new RuntimeException('forced failure inside the invite transaction');
    });

    expect(fn () => $this->withoutExceptionHandling()
        ->actingAs($s['admin'])
        ->postJson(convergenceInviteUrl($s), convergencePayload($s['creator'])))
        ->toThrow(RuntimeException::class);

    // This is what `store()`'s new transaction buys: before it, a failing audit
    // write left the assignment behind. Now the settle and the create stand or
    // fall together, and the emission never ran at all.
    expect(CampaignAssignment::query()->count())->toBe(0)
        ->and($application->fresh()?->status)->toBe(CampaignApplicationStatus::Pending)
        ->and($application->fresh()?->responded_at)->toBeNull()
        ->and(Notification::query()->count())->toBe(0);

    Mail::assertNothingQueued();
});

it('BULK: N invites through the loop settle only the applicant, leaving the others untouched', function (): void {
    Mail::fake();
    Feature::activate(ApplicationNotificationsEnabled::NAME);
    $s = convergenceSetup();

    $others = collect(range(1, 2))->map(fn (): Creator => CreatorFactory::new()->approved()->createOne());
    $application = pendingApplicationFor($s);

    // The bulk invite is N separate HTTP calls client-side, each transactional:
    // one creator's settle can never roll back another creator's invitation.
    foreach ([$s['creator'], ...$others->all()] as $creator) {
        $this->actingAs($s['admin'])
            ->postJson(convergenceInviteUrl($s), convergencePayload($creator))
            ->assertCreated();
    }

    expect(CampaignAssignment::query()->count())->toBe(3)
        ->and($application->fresh()?->status)->toBe(CampaignApplicationStatus::Accepted)
        // Exactly ONE settle happened across the whole loop.
        ->and(AuditLog::query()->where('action', AuditAction::CampaignApplicationAccepted->value)->count())->toBe(1)
        ->and(Notification::query()->where('type', NotificationType::CampaignApplicationAccepted->value)->count())->toBe(1);

    Mail::assertQueuedCount(1);
});
