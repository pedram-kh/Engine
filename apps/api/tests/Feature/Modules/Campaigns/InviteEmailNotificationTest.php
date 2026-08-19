<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Listeners\SendAssignmentNotifications;
use App\Modules\Campaigns\Mail\InviteReceivedMail;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Features\MissingCreatorMailsEnabled;
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
 * AH-083 (①) — the missing invite email, all THREE invite-shaped paths
 * (kickoff Q4): a fresh invite, the AH-035 re-offer after a decline, and the
 * agency's re-offer answering the creator's own counter. All three land on
 * `invited` and, per Q1, are handled by ONE match arm + ONE private method
 * ({@see SendAssignmentNotifications::notifyCreatorOfInvite()}).
 *
 * The three things pinned here mirror the ApplicationSubmittedNotificationTest
 * / CampaignApplicationAcceptTest precedent:
 *   - the MAIL leg's exact outcome copy (fresh vs re_offer, kickoff Q5);
 *   - the flag's exact reach — mail off, in-app row still written (dual-emit,
 *     kickoff Q2 — the break-revert anchor);
 *   - the in-app row's type is the SAME (`AssignmentInvited`) regardless of
 *     outcome — there is no `re_invited` NotificationType.
 *
 * §5.2 split: each of the three paths gets its own Event::fake dispatch
 * assertion (the AssignmentTransitioned leg) AND its own no-fake mail/in-app
 * assertions — a genuinely different test for each, not an extra assertion
 * bolted onto an unrelated one.
 */

/**
 * @return array{agency: Agency, brand: Brand, campaign: Campaign, admin: User, creator: Creator, creatorUser: User}
 */
function inviteMailSetup(): array
{
    $agency = Agency::factory()->createOne(['name' => 'Bright Harbour']);
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne([
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

    return [
        'agency' => $agency,
        'brand' => $brand,
        'campaign' => $campaign,
        'admin' => User::factory()->agencyAdmin($agency)->createOne(),
        'creator' => $creator,
        'creatorUser' => $creatorUser,
    ];
}

function inviteMailUrl(array $s): string
{
    return "/api/v1/agencies/{$s['agency']->ulid}/campaigns/{$s['campaign']->ulid}/assignments";
}

// ── Path 1 — the fresh invite (CampaignInvitationService::invite()) ─────────

it('fresh invite: dispatches AssignmentTransitioned with AssignmentInvited (§5.2 split)', function (): void {
    Event::fake([AssignmentTransitioned::class]);
    $s = inviteMailSetup();

    $this->actingAs($s['admin'])
        ->postJson(inviteMailUrl($s), [
            'creator_id' => $s['creator']->ulid,
            'agreed_fee_minor_units' => 500_000,
            'agreed_fee_currency' => 'EUR',
        ])
        ->assertCreated();

    Event::assertDispatched(
        AssignmentTransitioned::class,
        fn (AssignmentTransitioned $e): bool => $e->action === AuditAction::AssignmentInvited,
    );
});

it('fresh invite: FLAG ON queues InviteReceivedMail(outcome: fresh) and writes the in-app row', function (): void {
    Mail::fake();
    Feature::activate(MissingCreatorMailsEnabled::NAME);
    $s = inviteMailSetup();
    $s['creatorUser']->forceFill(['preferred_language' => 'de'])->save();

    $this->actingAs($s['admin'])
        ->postJson(inviteMailUrl($s), [
            'creator_id' => $s['creator']->ulid,
            'agreed_fee_minor_units' => 500_000,
            'agreed_fee_currency' => 'EUR',
        ])
        ->assertCreated();

    $assignment = CampaignAssignment::query()->firstOrFail();

    Mail::assertQueued(
        InviteReceivedMail::class,
        fn (InviteReceivedMail $mail): bool => $mail->hasTo($s['creatorUser']->email)
            && $mail->locale === 'de'
            && $mail->outcome === 'fresh'
            && $mail->campaignName === 'Autumn UGC push'
            && $mail->assignmentUlid === $assignment->ulid,
    );

    $row = Notification::query()->where('type', NotificationType::AssignmentInvited->value)->firstOrFail();
    expect($row->recipient_user_id)->toBe($s['creatorUser']->id)
        ->and($row->data)->toBe([
            'campaign_name' => 'Autumn UGC push',
            'creator_name' => 'Maria Lopez',
            'assignment_ulid' => $assignment->ulid,
        ]);
});

it('fresh invite: FLAG OFF queues no mail, but the in-app row still writes (break-revert anchor)', function (): void {
    Mail::fake();
    expect(Feature::active(MissingCreatorMailsEnabled::NAME))->toBeFalse();
    $s = inviteMailSetup();

    $this->actingAs($s['admin'])
        ->postJson(inviteMailUrl($s), [
            'creator_id' => $s['creator']->ulid,
            'agreed_fee_minor_units' => 500_000,
            'agreed_fee_currency' => 'EUR',
        ])
        ->assertCreated();

    Mail::assertNothingQueued();
    expect(Notification::query()->where('type', NotificationType::AssignmentInvited->value)->count())->toBe(1);
});

// ── Path 2 — the AH-035 re-offer after a decline (via the invite front-door) ─

/**
 * @return array{agency: Agency, brand: Brand, campaign: Campaign, admin: User, creator: Creator, creatorUser: User, assignment: CampaignAssignment}
 */
function declinedReofferSetup(): array
{
    $s = inviteMailSetup();
    $s['assignment'] = CampaignAssignment::factory()->status(AssignmentStatus::Declined)->create([
        'agency_id' => $s['agency']->id,
        'campaign_id' => $s['campaign']->id,
        'brand_id' => $s['brand']->id,
        'creator_id' => $s['creator']->id,
        'invited_by_user_id' => $s['admin']->id,
        'agreed_fee_minor_units' => 200_00,
        'agreed_fee_currency' => 'EUR',
        'responded_at' => now()->subDay(),
    ]);

    return $s;
}

it('re-offer after decline: dispatches AssignmentTransitioned with AssignmentReInvited (§5.2 split)', function (): void {
    Event::fake([AssignmentTransitioned::class]);
    $s = declinedReofferSetup();

    $this->actingAs($s['admin'])
        ->postJson(inviteMailUrl($s), [
            'creator_id' => $s['creator']->ulid,
            'agreed_fee_minor_units' => 350_00,
            'agreed_fee_currency' => 'EUR',
        ])
        ->assertOk();

    Event::assertDispatched(
        AssignmentTransitioned::class,
        fn (AssignmentTransitioned $e): bool => $e->action === AuditAction::AssignmentReInvited
            && $e->assignment->id === $s['assignment']->id,
    );
});

it('re-offer after decline: FLAG ON queues InviteReceivedMail(outcome: re_offer) and writes the SAME in-app type', function (): void {
    Mail::fake();
    Feature::activate(MissingCreatorMailsEnabled::NAME);
    $s = declinedReofferSetup();

    $this->actingAs($s['admin'])
        ->postJson(inviteMailUrl($s), [
            'creator_id' => $s['creator']->ulid,
            'agreed_fee_minor_units' => 350_00,
            'agreed_fee_currency' => 'EUR',
        ])
        ->assertOk();

    Mail::assertQueued(
        InviteReceivedMail::class,
        fn (InviteReceivedMail $mail): bool => $mail->hasTo($s['creatorUser']->email)
            && $mail->outcome === 're_offer',
    );

    // No `re_invited` NotificationType exists — the in-app row always reads
    // AssignmentInvited, the same as the fresh path.
    expect(Notification::query()->where('type', NotificationType::AssignmentInvited->value)->count())->toBe(1);
});

it('re-offer after decline: FLAG OFF queues no mail, but the in-app row still writes', function (): void {
    Mail::fake();
    expect(Feature::active(MissingCreatorMailsEnabled::NAME))->toBeFalse();
    $s = declinedReofferSetup();

    $this->actingAs($s['admin'])
        ->postJson(inviteMailUrl($s), [
            'creator_id' => $s['creator']->ulid,
            'agreed_fee_minor_units' => 350_00,
            'agreed_fee_currency' => 'EUR',
        ])
        ->assertOk();

    Mail::assertNothingQueued();
    expect(Notification::query()->where('type', NotificationType::AssignmentInvited->value)->count())->toBe(1);
});

// ── Path 3 — the counter-response re-offer (countered → invited, /reinvite) ──

/**
 * @return array{agency: Agency, brand: Brand, campaign: Campaign, admin: User, creator: Creator, creatorUser: User, assignment: CampaignAssignment}
 */
function counteredReinviteSetup(): array
{
    $s = inviteMailSetup();
    $s['assignment'] = CampaignAssignment::factory()->status(AssignmentStatus::Countered)->create([
        'agency_id' => $s['agency']->id,
        'campaign_id' => $s['campaign']->id,
        'brand_id' => $s['brand']->id,
        'creator_id' => $s['creator']->id,
        'invited_by_user_id' => $s['admin']->id,
        'agreed_fee_minor_units' => 500_000,
        'agreed_fee_currency' => 'EUR',
        'countered_fee_minor_units' => 700_000,
        'countered_fee_currency' => 'EUR',
    ]);

    return $s;
}

function reinviteUrl(array $s): string
{
    return inviteMailUrl($s)."/{$s['assignment']->ulid}/reinvite";
}

it('reinvite (countered → invited): dispatches AssignmentTransitioned with AssignmentReInvited (§5.2 split)', function (): void {
    Event::fake([AssignmentTransitioned::class]);
    $s = counteredReinviteSetup();

    $this->actingAs($s['admin'])
        ->postJson(reinviteUrl($s), ['agreed_fee_minor_units' => 650_000, 'agreed_fee_currency' => 'EUR'])
        ->assertOk();

    Event::assertDispatched(
        AssignmentTransitioned::class,
        fn (AssignmentTransitioned $e): bool => $e->action === AuditAction::AssignmentReInvited
            && $e->assignment->id === $s['assignment']->id,
    );
});

it('reinvite: FLAG ON queues InviteReceivedMail(outcome: re_offer) — the creator whose counter was answered deserves the email most of the three (kickoff Q4)', function (): void {
    Mail::fake();
    Feature::activate(MissingCreatorMailsEnabled::NAME);
    $s = counteredReinviteSetup();

    $this->actingAs($s['admin'])
        ->postJson(reinviteUrl($s), ['agreed_fee_minor_units' => 650_000, 'agreed_fee_currency' => 'EUR'])
        ->assertOk();

    Mail::assertQueued(
        InviteReceivedMail::class,
        fn (InviteReceivedMail $mail): bool => $mail->hasTo($s['creatorUser']->email)
            && $mail->outcome === 're_offer'
            && $mail->assignmentUlid === $s['assignment']->ulid,
    );
    expect(Notification::query()->where('type', NotificationType::AssignmentInvited->value)->count())->toBe(1);
});

it('reinvite: FLAG OFF queues no mail, but the in-app row still writes', function (): void {
    Mail::fake();
    expect(Feature::active(MissingCreatorMailsEnabled::NAME))->toBeFalse();
    $s = counteredReinviteSetup();

    $this->actingAs($s['admin'])
        ->postJson(reinviteUrl($s), ['agreed_fee_minor_units' => 650_000, 'agreed_fee_currency' => 'EUR'])
        ->assertOk();

    Mail::assertNothingQueued();
    expect(Notification::query()->where('type', NotificationType::AssignmentInvited->value)->count())->toBe(1);
});
