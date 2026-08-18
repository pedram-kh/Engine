<?php

declare(strict_types=1);

use App\Modules\Agencies\Enums\BlacklistType;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Agencies\Models\BrandCreatorBlacklist;
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
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Features\ApplicationNotificationsEnabled;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorAvailabilityBlock;
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
 * AH-058 (chunk 4, D2 + D3) — ACCEPT an application: the cross-table
 * transaction, the pair-collision matrix, and the two gate legs that differ from
 * the direct-invite path.
 *
 * The centrepiece is the FORCED-FAILURE test at the bottom of the transaction
 * section. Everything else here could pass while the accept still tore in half
 * under load; only a test that makes the second write fail proves the first one
 * rolls back — and proves that nothing was mailed on the way out.
 */

/**
 * The accept happy path: an approved, rostered creator with a PENDING
 * application on a listed campaign, and an agency admin to act.
 *
 * @return array{agency: Agency, brand: Brand, campaign: Campaign, creator: Creator, creatorUser: User, application: CampaignApplication, admin: User}
 */
function acceptSetup(array $campaignOverrides = []): array
{
    $agency = Agency::factory()->createOne(['name' => 'Bright Harbour']);
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->listed()->createOne(array_merge([
        'agency_id' => $agency->id,
        'brand_id' => $brand->id,
        'name' => 'Autumn UGC push',
        'budget_currency' => 'EUR',
    ], $campaignOverrides));

    $creatorUser = User::factory()->creator()->createOne(['name' => 'Maria']);
    $creator = CreatorFactory::new()->approved()->createOne([
        'user_id' => $creatorUser->id,
        'display_name' => 'Maria Lopez',
    ]);

    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
    ]);

    $application = CampaignApplication::factory()->createOne([
        'campaign_id' => $campaign->id,
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
    ]);

    return [
        'agency' => $agency,
        'brand' => $brand,
        'campaign' => $campaign,
        'creator' => $creator,
        'creatorUser' => $creatorUser,
        'application' => $application,
        'admin' => User::factory()->agencyAdmin($agency)->createOne(),
    ];
}

function acceptUrl(array $s): string
{
    return "/api/v1/agencies/{$s['agency']->ulid}/campaigns/{$s['campaign']->ulid}/applications/{$s['application']->ulid}/accept";
}

/**
 * @return array<string, mixed>
 */
function acceptPayload(array $overrides = []): array
{
    return array_merge([
        'agreed_fee_minor_units' => 500_000,
        'agreed_fee_currency' => 'EUR',
    ], $overrides);
}

function reloadApplication(CampaignApplication $application): CampaignApplication
{
    return $application->fresh() ?? $application;
}

// ── The happy path: byte-indistinguishable from a cold invite ────────────────

it('accepts: flips the application, creates an INVITED assignment, and can still be declined', function (): void {
    Mail::fake();
    $s = acceptSetup();

    $this->actingAs($s['admin'])
        ->postJson(acceptUrl($s), acceptPayload([
            'fee_per' => 'per video',
            'offer_description' => 'Two 30s verticals.',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.attributes.status', 'invited');

    $application = reloadApplication($s['application']);
    $assignment = CampaignAssignment::query()->firstOrFail();

    expect($application->status)->toBe(CampaignApplicationStatus::Accepted)
        ->and($application->responded_at)->not->toBeNull()
        // The offer landed on the assignment, in full.
        ->and($assignment->status)->toBe(AssignmentStatus::Invited)
        ->and($assignment->agreed_fee_minor_units)->toBe(500_000)
        ->and($assignment->agreed_fee_currency)->toBe('EUR')
        ->and($assignment->fee_per)->toBe('per video')
        ->and($assignment->offer_description)->toBe('Two 30s verticals.')
        // `invited_by_user_id` is the ACCEPTING user — the honest attribution.
        ->and($assignment->invited_by_user_id)->toBe($s['admin']->id)
        ->and($assignment->invited_at)->not->toBeNull()
        // Landing in `invited` is the whole point: the creator now answers the
        // real offer, so an applicant can still DECLINE (apply ≠ contract).
        ->and($assignment->responded_at)->toBeNull();
});

it('accepts: writes BOTH audit rows — the application flip and the assignment birth', function (): void {
    Mail::fake();
    $s = acceptSetup();

    $this->actingAs($s['admin'])->postJson(acceptUrl($s), acceptPayload())->assertCreated();

    $actions = AuditLog::query()->pluck('action')->map(
        static fn (AuditAction|string $action): string => $action instanceof AuditAction ? $action->value : $action,
    )->all();

    // Two separate facts, separately auditable: one application moved to
    // accepted, and one assignment was born.
    expect($actions)->toContain(AuditAction::CampaignApplicationAccepted->value)
        ->and($actions)->toContain(AuditAction::AssignmentInvited->value);
});

it('accepts: the assignment is byte-indistinguishable downstream — event + board card + thread', function (): void {
    Mail::fake();
    $s = acceptSetup();

    $this->actingAs($s['admin'])->postJson(acceptUrl($s), acceptPayload())->assertCreated();

    $assignment = CampaignAssignment::query()->firstOrFail();

    // The board is ASSERTED, not rebuilt (D1): CreateBoardCard fires on
    // `assignment.invited` and the seeded automation moves the card to Invited.
    // If this ever reddens, the accept stopped dispatching the event — which is
    // exactly the failure the shared invitation service exists to prevent.
    $card = BoardCard::query()->where('assignment_id', $assignment->id)->first();

    expect($card)->not->toBeNull()
        ->and($card?->column?->name)->toBe('Invited');
});

it('accepts: dispatches AssignmentTransitioned once, from=to=invited (§5.2 dispatch leg)', function (): void {
    Event::fake([AssignmentTransitioned::class]);
    $s = acceptSetup();

    $this->actingAs($s['admin'])->postJson(acceptUrl($s), acceptPayload())->assertCreated();

    Event::assertDispatched(
        AssignmentTransitioned::class,
        fn (AssignmentTransitioned $event): bool => $event->from === AssignmentStatus::Invited
            && $event->to === AssignmentStatus::Invited
            && $event->action === AuditAction::AssignmentInvited,
    );
    Event::assertDispatchedTimes(AssignmentTransitioned::class, 1);
});

it('accepts: emits the creator notification AFTER the commit, with the assignment link', function (): void {
    Mail::fake();
    Feature::activate(ApplicationNotificationsEnabled::NAME);
    $s = acceptSetup();
    $s['creatorUser']->forceFill(['preferred_language' => 'de'])->save();

    $this->actingAs($s['admin'])->postJson(acceptUrl($s), acceptPayload())->assertCreated();

    $assignment = CampaignAssignment::query()->firstOrFail();
    $row = Notification::query()
        ->where('type', NotificationType::CampaignApplicationAccepted->value)
        ->firstOrFail();

    expect($row->recipient_user_id)->toBe($s['creatorUser']->id)
        ->and($row->data)->toBe([
            'agency_name' => 'Bright Harbour',
            'campaign_name' => 'Autumn UGC push',
            'assignment_ulid' => $assignment->ulid,
        ]);

    Mail::assertQueued(
        ApplicationAcceptedMail::class,
        fn (ApplicationAcceptedMail $mail): bool => $mail->hasTo($s['creatorUser']->email)
            && $mail->locale === 'de'
            && str_contains($mail->actionUrl, $assignment->ulid),
    );
});

it('FLAG OFF: accept still works, still writes the in-app row, and queues no mail', function (): void {
    Mail::fake();
    expect(Feature::active(ApplicationNotificationsEnabled::NAME))->toBeFalse();
    $s = acceptSetup();

    $this->actingAs($s['admin'])->postJson(acceptUrl($s), acceptPayload())->assertCreated();

    Mail::assertNothingQueued();
    expect(Notification::query()->where('type', NotificationType::CampaignApplicationAccepted->value)->count())
        ->toBe(1);
});

// ── Review priority 1 — the transaction break-revert ────────────────────────

it('ROLLBACK: a failure after the application flip leaves NO assignment, the application PENDING, and nothing mailed', function (): void {
    Mail::fake();
    Feature::activate(ApplicationNotificationsEnabled::NAME);
    $s = acceptSetup();

    // Force a failure at the LAST step inside the transaction — the event the
    // invitation service dispatches — so the application flip, the assignment
    // insert and both audit rows are already done when it blows up. Reading the
    // code proves a transaction is written; only this proves it holds.
    //
    // A throwing listener rather than a test double, because the listeners are
    // synchronous and in-transaction by design: this is the real failure mode
    // (any one of the three `invited` consumers throwing), not a simulated one.
    Event::listen(AssignmentTransitioned::class, function (): void {
        throw new RuntimeException('forced failure inside the accept transaction');
    });

    expect(fn () => $this->withoutExceptionHandling()
        ->actingAs($s['admin'])
        ->postJson(acceptUrl($s), acceptPayload()))
        ->toThrow(RuntimeException::class);

    $application = reloadApplication($s['application']);

    // The application flip rolled back with the assignment that never survived.
    expect($application->status)->toBe(CampaignApplicationStatus::Pending)
        ->and($application->responded_at)->toBeNull()
        ->and(CampaignAssignment::query()->count())->toBe(0)
        ->and(BoardCard::query()->count())->toBe(0)
        // …and both audit rows went with it.
        ->and(AuditLog::query()->where('action', AuditAction::CampaignApplicationAccepted->value)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::AssignmentInvited->value)->count())->toBe(0)
        // C1: the emission sits AFTER the transaction, so a rolled-back accept
        // cannot have told the creator anything. `after_commit => false` means a
        // mail queued INSIDE the transaction would already be on its way — these
        // two assertions are the ones that catch that mistake.
        ->and(Notification::query()->count())->toBe(0);

    Mail::assertNothingQueued();
});

// ── D3 — the pair-collision matrix, one case per pre-existing state ─────────

it('D3 · no assignment → creates one (201)', function (): void {
    Mail::fake();
    $s = acceptSetup();

    $this->actingAs($s['admin'])->postJson(acceptUrl($s), acceptPayload())->assertCreated();

    expect(CampaignAssignment::query()->count())->toBe(1);
});

it('D3 · a DECLINED assignment → the AH-035 re-offer edge on the SAME row (200)', function (): void {
    Mail::fake();
    $s = acceptSetup();

    $declined = CampaignAssignment::factory()->status(AssignmentStatus::Declined)->createOne([
        'agency_id' => $s['agency']->id,
        'campaign_id' => $s['campaign']->id,
        'brand_id' => $s['brand']->id,
        'creator_id' => $s['creator']->id,
        'invited_by_user_id' => $s['admin']->id,
    ]);

    $this->actingAs($s['admin'])
        ->postJson(acceptUrl($s), acceptPayload(['agreed_fee_minor_units' => 750_000]))
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'invited');

    $assignment = $declined->fresh() ?? $declined;

    // ONE row, re-opened — not a second one. The thread, the card and the
    // history survive, and the fresh offer is on it.
    expect(CampaignAssignment::query()->count())->toBe(1)
        ->and($assignment->status)->toBe(AssignmentStatus::Invited)
        ->and($assignment->agreed_fee_minor_units)->toBe(750_000)
        ->and($assignment->previously_declined)->toBeTrue()
        ->and($assignment->responded_at)->toBeNull()
        ->and(reloadApplication($s['application'])->status)->toBe(CampaignApplicationStatus::Accepted);
});

it('D3 · any other assignment status → 422 application.already_engaged, naming the engagement', function (AssignmentStatus $status): void {
    Mail::fake();
    $s = acceptSetup();

    $existing = CampaignAssignment::factory()->status($status)->createOne([
        'agency_id' => $s['agency']->id,
        'campaign_id' => $s['campaign']->id,
        'brand_id' => $s['brand']->id,
        'creator_id' => $s['creator']->id,
        'invited_by_user_id' => $s['admin']->id,
    ]);

    $this->actingAs($s['admin'])
        ->postJson(acceptUrl($s), acceptPayload())
        ->assertStatus(422)
        ->assertJsonPath('meta.code', 'application.already_engaged')
        ->assertJsonPath('meta.assignment_status', $status->value)
        ->assertJsonPath('meta.assignment_id', $existing->ulid);

    // Refused BEFORE any write: the application is untouched and no second
    // assignment exists.
    expect(reloadApplication($s['application'])->status)->toBe(CampaignApplicationStatus::Pending)
        ->and(reloadApplication($s['application'])->responded_at)->toBeNull()
        ->and(CampaignAssignment::query()->count())->toBe(1);
})->with([
    AssignmentStatus::Invited,
    AssignmentStatus::Accepted,
    AssignmentStatus::Countered,
    AssignmentStatus::Contracted,
    AssignmentStatus::Cancelled,
]);

// ── §5.6 — idempotency ──────────────────────────────────────────────────────

it('a second accept is 422 with no second assignment and an unchanged responded_at', function (): void {
    Mail::fake();
    $s = acceptSetup();

    $this->actingAs($s['admin'])->postJson(acceptUrl($s), acceptPayload())->assertCreated();
    $firstResponse = reloadApplication($s['application'])->responded_at;

    $this->travel(5)->minutes();

    $this->actingAs($s['admin'])
        ->postJson(acceptUrl($s), acceptPayload())
        ->assertStatus(422)
        ->assertJsonPath('meta.code', 'application.not_pending')
        ->assertJsonPath('meta.status', 'accepted');

    expect(CampaignAssignment::query()->count())->toBe(1)
        // The timestamp records WHEN the agency answered; re-answering does not
        // move that moment.
        ->and(reloadApplication($s['application'])->responded_at?->toIso8601String())
        ->toBe($firstResponse?->toIso8601String());
});

it('a REJECTED application cannot be accepted', function (): void {
    Mail::fake();
    $s = acceptSetup();
    $s['application']->forceFill([
        'status' => CampaignApplicationStatus::Rejected,
        'responded_at' => now(),
    ])->save();

    $this->actingAs($s['admin'])
        ->postJson(acceptUrl($s), acceptPayload())
        ->assertStatus(422)
        ->assertJsonPath('meta.code', 'application.not_pending');

    expect(CampaignAssignment::query()->count())->toBe(0);
});

// ── The gate legs (review priority 3) ──────────────────────────────────────

it('BREAK-REVERT · a creator hidden from discovery is STILL accepted (the dropped is_discoverable leg)', function (): void {
    Mail::fake();
    $s = acceptSetup();

    // AH-051's ruling verbatim: a browsing preference is not an eligibility gate.
    // This creator applied and has since hidden from discovery — which is MORE
    // interest in this campaign, not less.
    $s['creator']->forceFill(['is_discoverable' => false])->save();

    $this->actingAs($s['admin'])->postJson(acceptUrl($s), acceptPayload())->assertCreated();

    expect(reloadApplication($s['application'])->status)->toBe(CampaignApplicationStatus::Accepted)
        ->and(CampaignAssignment::query()->count())->toBe(1);

    // BREAK-REVERT: re-add `->where('is_discoverable', true)` to the accept
    // path's creator resolution and this test reddens with a 422/404.
});

it('BREAK-REVERT · an agency-wide HARD blacklist added after the application 422s the accept', function (): void {
    Mail::fake();
    $s = acceptSetup();

    // The blacklist POSTDATES the application — the one gate whose answer can
    // have changed since the creator applied, which is why it is re-checked.
    AgencyCreatorRelation::query()
        ->where('agency_id', $s['agency']->id)
        ->where('creator_id', $s['creator']->id)
        ->update(['is_blacklisted' => true, 'blacklist_type' => BlacklistType::Hard->value]);

    $this->actingAs($s['admin'])
        ->postJson(acceptUrl($s), acceptPayload())
        ->assertStatus(422)
        ->assertJsonPath('meta.code', 'assignment.blacklisted');

    expect(reloadApplication($s['application'])->status)->toBe(CampaignApplicationStatus::Pending)
        ->and(CampaignAssignment::query()->count())->toBe(0);
});

it('a BRAND-scoped hard blacklist 422s the accept too (both predicates)', function (): void {
    Mail::fake();
    $s = acceptSetup();

    BrandCreatorBlacklist::factory()->createOne([
        'brand_id' => $s['brand']->id,
        'creator_id' => $s['creator']->id,
        'blacklist_type' => BlacklistType::Hard,
    ]);

    $this->actingAs($s['admin'])
        ->postJson(acceptUrl($s), acceptPayload())
        ->assertStatus(422)
        ->assertJsonPath('meta.code', 'assignment.blacklisted');

    expect(CampaignAssignment::query()->count())->toBe(0);
});

it('a SOFT blacklist does not block — the tiers stay distinct severities', function (): void {
    Mail::fake();
    $s = acceptSetup();

    AgencyCreatorRelation::query()
        ->where('agency_id', $s['agency']->id)
        ->where('creator_id', $s['creator']->id)
        ->update(['is_blacklisted' => true, 'blacklist_type' => BlacklistType::Soft->value]);

    $this->actingAs($s['admin'])->postJson(acceptUrl($s), acceptPayload())->assertCreated();
});

it('an availability conflict is a 409 the agency re-submits past with acknowledged', function (): void {
    Mail::fake();
    $s = acceptSetup([
        'starts_at' => now()->addDays(3)->toDateString(),
        'ends_at' => now()->addDays(10)->toDateString(),
    ]);

    CreatorAvailabilityBlock::factory()->hard()->createOne([
        'creator_id' => $s['creator']->id,
        'starts_at' => now()->addDays(4),
        'ends_at' => now()->addDays(6),
    ]);

    $this->actingAs($s['admin'])
        ->postJson(acceptUrl($s), acceptPayload())
        ->assertStatus(409)
        ->assertJsonPath('meta.code', 'assignment.availability_conflict');

    // The signal survived the accept path (it is the same gate), and nothing was
    // written while the agency decided.
    expect(reloadApplication($s['application'])->status)->toBe(CampaignApplicationStatus::Pending)
        ->and(CampaignAssignment::query()->count())->toBe(0);

    $this->actingAs($s['admin'])
        ->postJson(acceptUrl($s), acceptPayload(['acknowledged' => true]))
        ->assertCreated();

    expect(CampaignAssignment::query()->count())->toBe(1);
});

it('422s when the applicant is no longer an approved creator', function (): void {
    Mail::fake();
    $s = acceptSetup();
    $s['creator']->forceFill(['application_status' => ApplicationStatus::Rejected])->save();

    $this->actingAs($s['admin'])
        ->postJson(acceptUrl($s), acceptPayload())
        ->assertStatus(422)
        ->assertJsonPath('meta.code', 'application.creator_not_approved');

    expect(CampaignAssignment::query()->count())->toBe(0);
});

// ── Payload validation (the shared offer rules) ─────────────────────────────

it('validates the offer exactly as the invite path does — fee, currency, cap', function (): void {
    Mail::fake();
    $s = acceptSetup();

    $this->actingAs($s['admin'])
        ->postJson(acceptUrl($s), ['agreed_fee_minor_units' => 0, 'agreed_fee_currency' => 'EUR'])
        ->assertUnprocessable();

    // The campaign currency cross-check, shared through the offer-rules trait.
    $this->actingAs($s['admin'])
        ->postJson(acceptUrl($s), acceptPayload(['agreed_fee_currency' => 'USD']))
        ->assertUnprocessable();

    // AH-081: cap raised 2000 -> 3000 to accommodate the minimal-rich-text
    // markdown syntax overhead ("**", "[]()") on top of visible prose.
    $this->actingAs($s['admin'])
        ->postJson(acceptUrl($s), acceptPayload(['offer_description' => str_repeat('x', 3001)]))
        ->assertUnprocessable();

    expect(CampaignAssignment::query()->count())->toBe(0);
});

it('does NOT accept a creator_id in the body — the applicant is the application\'s own creator', function (): void {
    Mail::fake();
    $s = acceptSetup();
    $interloper = CreatorFactory::new()->approved()->createOne();

    $this->actingAs($s['admin'])
        ->postJson(acceptUrl($s), acceptPayload(['creator_id' => $interloper->ulid]))
        ->assertCreated();

    // The body's creator_id is ignored, not honoured: accepting one application
    // on another creator's behalf must be impossible by construction.
    expect(CampaignAssignment::query()->firstOrFail()->creator_id)->toBe($s['creator']->id);
});

// ── Authorization + tenancy ─────────────────────────────────────────────────

it('agency STAFF may accept — the weakest role holding `invite` (Q4, recorded)', function (): void {
    Mail::fake();
    $s = acceptSetup();
    $staff = User::factory()->agencyStaff($s['agency'])->createOne();

    // Q4's choice pinned from the outside: accept and reject sit under the SAME
    // execute ability as the direct invite, so whoever may put an offer in front
    // of a creator may answer their application. There is deliberately no fourth
    // role between `view` and `invite` — the three-role set IS the invite set.
    $this->actingAs($staff)->postJson(acceptUrl($s), acceptPayload())->assertCreated();
});

it('404s a caller with no membership in the agency', function (): void {
    Mail::fake();
    $s = acceptSetup();
    $outsider = User::factory()->agencyAdmin()->createOne();

    // 404, not 403: the tenancy scope makes another agency's campaign
    // non-existent rather than merely forbidden (§5.4 non-fingerprinting).
    $this->actingAs($outsider)->postJson(acceptUrl($s), acceptPayload())->assertNotFound();

    expect(reloadApplication($s['application'])->status)->toBe(CampaignApplicationStatus::Pending)
        ->and(CampaignAssignment::query()->count())->toBe(0);
});

it('404s an application belonging to another campaign of the same agency', function (): void {
    Mail::fake();
    $s = acceptSetup();

    $sibling = Campaign::factory()->listed()->createOne([
        'agency_id' => $s['agency']->id,
        'brand_id' => $s['brand']->id,
        'budget_currency' => 'EUR',
    ]);

    $url = "/api/v1/agencies/{$s['agency']->ulid}/campaigns/{$sibling->ulid}/applications/{$s['application']->ulid}/accept";

    $this->actingAs($s['admin'])->postJson($url, acceptPayload())->assertNotFound();

    expect(reloadApplication($s['application'])->status)->toBe(CampaignApplicationStatus::Pending);
});
