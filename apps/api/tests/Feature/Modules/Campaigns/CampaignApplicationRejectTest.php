<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Campaigns\Mail\ApplicationRejectedMail;
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
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-058 (chunk 4, D4) — REJECT an application: the agency's polite no.
 *
 * The shape is deliberately small — a status flip, a timestamp, an audit row and
 * one notification pair — so what this spec mostly pins is what reject does NOT
 * do: no reason column, no second `responded_at` on a re-reject, no assignment,
 * and no re-apply for the creator afterwards (the terminal row already stops it,
 * which is asserted here rather than assumed).
 */

/**
 * @return array{agency: Agency, brand: Brand, campaign: Campaign, creator: Creator, creatorUser: User, application: CampaignApplication, admin: User}
 */
function rejectSetup(): array
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

function rejectUrl(array $s): string
{
    return "/api/v1/agencies/{$s['agency']->ulid}/campaigns/{$s['campaign']->ulid}/applications/{$s['application']->ulid}/reject";
}

// ── The happy path ──────────────────────────────────────────────────────────

it('rejects: flips the application to rejected and stamps responded_at', function (): void {
    Mail::fake();
    $s = rejectSetup();

    $this->actingAs($s['admin'])
        ->postJson(rejectUrl($s))
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'rejected')
        ->assertJsonPath('meta.code', 'application.rejected');

    $application = $s['application']->fresh();

    expect($application?->status)->toBe(CampaignApplicationStatus::Rejected)
        ->and($application?->responded_at)->not->toBeNull()
        // Reject writes ONE row and creates nothing: no assignment, and so no
        // board card, no message thread, no offer.
        ->and(CampaignAssignment::query()->count())->toBe(0);
});

it('rejects: writes the audit row with its cause, and no agency reason anywhere', function (): void {
    Mail::fake();
    $s = rejectSetup();

    // A `reason` in the body is not a validation error — it is simply not a
    // field. D4: no reason is stored because none is ever shown, so a caller
    // sending one must not be able to smuggle it into the record.
    $this->actingAs($s['admin'])->postJson(rejectUrl($s), ['reason' => 'Not a fit'])->assertOk();

    $row = AuditLog::query()
        ->where('action', AuditAction::CampaignApplicationRejected->value)
        ->sole();

    expect($row->actor_id)->toBe($s['admin']->id)
        ->and($row->agency_id)->toBe($s['agency']->id)
        ->and($row->subject_type)->toBe(CampaignApplication::class)
        ->and($row->subject_id)->toBe($s['application']->id)
        // The cause is on the row because "who or what closed this" is the only
        // question a reader of the log will have — the agency answered, rather
        // than the campaign closing underneath it (D5's other cause).
        ->and($row->metadata['cause'] ?? null)->toBe('agency_rejected')
        ->and($row->metadata)->not->toHaveKey('reason')
        ->and($row->reason)->toBeNull();
});

it('rejects: notifies the creator in-app with cause=agency_rejected and queues the mail', function (): void {
    Mail::fake();
    Feature::activate(ApplicationNotificationsEnabled::NAME);
    $s = rejectSetup();

    $this->actingAs($s['admin'])->postJson(rejectUrl($s))->assertOk();

    $row = Notification::query()
        ->where('type', NotificationType::CampaignApplicationRejected->value)
        ->sole();

    expect($row->recipient_user_id)->toBe($s['creatorUser']->id)
        // No actor: the reject is an AGENCY act, and naming the employee who
        // pressed it puts their name in front of a rejected creator.
        ->and($row->actor_user_id)->toBeNull()
        ->and($row->data['campaign_name'] ?? null)->toBe('Autumn UGC push')
        // Q8: `data.cause` is part of the notification CONTRACT — the SPA
        // template may read it and the mailable's blade appends it to `body_` to
        // pick the variant. Renaming the key or its values is a breaking change.
        ->and($row->data['cause'] ?? null)->toBe('agency_rejected');

    Mail::assertQueued(ApplicationRejectedMail::class, fn (ApplicationRejectedMail $mail): bool => $mail->hasTo($s['creatorUser']->email));
});

it('FLAG OFF: no mail is queued, and the in-app row is STILL written', function (): void {
    Mail::fake();
    Feature::deactivate(ApplicationNotificationsEnabled::NAME);
    $s = rejectSetup();

    $this->actingAs($s['admin'])->postJson(rejectUrl($s))->assertOk();

    // The flag gates MAIL; in-app honours the recipient's own preference. The
    // creator is told inside the product on day one — the fan-out risk that
    // justifies a flag is the outbound mail, not a row in a table.
    expect(Notification::query()->where('type', NotificationType::CampaignApplicationRejected->value)->count())->toBe(1);

    Mail::assertNothingQueued();
});

// ── §5.6: the source guard, hand-written because the enum has no machine ─────

it('a second reject is 422 and does NOT move responded_at', function (): void {
    Mail::fake();
    Feature::activate(ApplicationNotificationsEnabled::NAME);
    $s = rejectSetup();

    $this->actingAs($s['admin'])->postJson(rejectUrl($s))->assertOk();

    $firstRespondedAt = $s['application']->fresh()?->responded_at;

    $this->travel(5)->minutes();

    $this->actingAs($s['admin'])
        ->postJson(rejectUrl($s))
        ->assertStatus(422)
        ->assertJsonPath('meta.code', 'application.not_pending')
        ->assertJsonPath('meta.status', 'rejected');

    // `responded_at` records WHEN the agency answered, and re-answering does not
    // move that moment. The second call also emits nothing: a creator must not be
    // told twice that they were not selected.
    expect($s['application']->fresh()?->responded_at?->toIso8601String())->toBe($firstRespondedAt?->toIso8601String())
        ->and(AuditLog::query()->where('action', AuditAction::CampaignApplicationRejected->value)->count())->toBe(1)
        ->and(Notification::query()->where('type', NotificationType::CampaignApplicationRejected->value)->count())->toBe(1);

    Mail::assertQueuedCount(1);
});

it('an ACCEPTED application cannot be rejected — the pending-only guard cuts both ways', function (): void {
    Mail::fake();
    $s = rejectSetup();

    $s['application']->forceFill([
        'status' => CampaignApplicationStatus::Accepted,
        'responded_at' => now()->subHour(),
    ])->save();

    $this->actingAs($s['admin'])
        ->postJson(rejectUrl($s))
        ->assertStatus(422)
        ->assertJsonPath('meta.code', 'application.not_pending')
        ->assertJsonPath('meta.status', 'accepted');

    expect($s['application']->fresh()?->status)->toBe(CampaignApplicationStatus::Accepted);
});

// ── The composition c3 already shipped: no re-apply ─────────────────────────

it('a rejected creator cannot re-apply — the retained terminal row occupies the unique pair', function (): void {
    Mail::fake();
    $s = rejectSetup();

    $this->actingAs($s['admin'])->postJson(rejectUrl($s))->assertOk();

    // Reject RETAINS the row rather than deleting it, and c3's unique
    // (campaign_id, creator_id) pair turns that into the no-re-apply rule for
    // free. This is the assertion that keeps a future "clean up rejected
    // applications" idea from silently reopening the door.
    $this->actingAs($s['creatorUser'])
        ->postJson("/api/v1/creators/me/jobs/{$s['campaign']->ulid}/apply")
        ->assertStatus(409)
        // The code is the SHARPER one c3 already ships for this exact state:
        // `job.application_rejected` rather than the generic already-applied, so
        // the creator's page can say "you were not selected" instead of implying
        // an application is still in flight.
        ->assertJsonPath('errors.0.code', 'job.application_rejected');
});

// ── Authorization + tenancy ─────────────────────────────────────────────────

it('agency STAFF may reject — the same execute ability as accept (Q4, recorded)', function (): void {
    Mail::fake();
    $s = rejectSetup();
    $staff = User::factory()->agencyStaff($s['agency'])->createOne();

    $this->actingAs($staff)->postJson(rejectUrl($s))->assertOk();
});

it('404s a caller with no membership in the agency', function (): void {
    Mail::fake();
    $s = rejectSetup();
    $outsider = User::factory()->agencyAdmin()->createOne();

    $this->actingAs($outsider)->postJson(rejectUrl($s))->assertNotFound();

    expect($s['application']->fresh()?->status)->toBe(CampaignApplicationStatus::Pending);
});

it('404s an application belonging to another campaign of the same agency', function (): void {
    Mail::fake();
    $s = rejectSetup();

    $sibling = Campaign::factory()->listed()->createOne([
        'agency_id' => $s['agency']->id,
        'brand_id' => $s['brand']->id,
        'budget_currency' => 'EUR',
    ]);

    $url = "/api/v1/agencies/{$s['agency']->ulid}/campaigns/{$sibling->ulid}/applications/{$s['application']->ulid}/reject";

    $this->actingAs($s['admin'])->postJson($url)->assertNotFound();

    expect($s['application']->fresh()?->status)->toBe(CampaignApplicationStatus::Pending);
});
