<?php

declare(strict_types=1);

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Campaigns\Mail\ApplicationSubmittedMail;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Creators\Features\ApplicationNotificationsEnabled;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;
use Tests\Fixtures\JobsBoard\CreatorJobFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-058 (chunk 4, D6) — the `campaign_application.submitted` dual emit: the
 * verb chunk 3 wrote as audit-only and deliberately left un-notified until the
 * agency had a surface to act on it.
 *
 * The three things pinned here are the three that can silently go wrong:
 *   - WHO receives it (admins + managers, staff excluded — the load-bearing
 *     exclusion, and an asymmetry this chunk records rather than fixes);
 *   - the FLAG's exact reach: mail off, in-app rows still written;
 *   - the recipient's own `in_app` preference still winning over both.
 *
 * §5.2 split: the mail leg is asserted under `Mail::fake()`, and the no-mail leg
 * is its own test rather than an extra assertion — a flag that silences mail is
 * only proven by a test whose ONLY subject is the silence.
 */

/**
 * The apply happy path with an agency that has real members: an admin, a
 * manager, and a staff member who must NOT be notified.
 *
 * @return array{fixture: CreatorJobFixture, admin: User, manager: User, staff: User}
 */
function submittedFixture(): array
{
    $fixture = CreatorJobFixture::make();

    return [
        'fixture' => $fixture,
        'admin' => User::factory()->agencyAdmin($fixture->agency)->createOne(),
        'manager' => User::factory()->agencyManager($fixture->agency)->createOne(),
        'staff' => User::factory()->agencyStaff($fixture->agency)->createOne(),
    ];
}

it('notifies every agency admin + manager in-app, and never the staff member', function (): void {
    Mail::fake();
    $s = submittedFixture();

    $this->actingAs($s['fixture']->user)->postJson($s['fixture']->applyUrl())->assertCreated();

    $recipients = Notification::query()
        ->where('type', NotificationType::CampaignApplicationSubmitted->value)
        ->pluck('recipient_user_id')
        ->all();

    expect($recipients)->toHaveCount(2)
        ->and($recipients)->toContain($s['admin']->id)
        ->and($recipients)->toContain($s['manager']->id)
        // Staff can invite but are not told when someone applies. That is
        // Agency::notifiableMembers()'s load-bearing exclusion, applied here for
        // consistency with every other agency-facing notification, and recorded
        // in the review as pre-existing rather than fixed in this chunk.
        ->and($recipients)->not->toContain($s['staff']->id);
});

it('carries the render params the SPA template interpolates, and no free-text note', function (): void {
    Mail::fake();
    $s = submittedFixture();

    $this->actingAs($s['fixture']->user)
        ->postJson($s['fixture']->applyUrl(), ['note' => 'I shot a similar campaign last spring.'])
        ->assertCreated();

    $row = Notification::query()
        ->where('type', NotificationType::CampaignApplicationSubmitted->value)
        ->firstOrFail();

    expect(array_keys($row->data ?? []))->toBe(['creator_name', 'campaign_name'])
        ->and($row->data['campaign_name'] ?? null)->toBe(CreatorJobFixture::CAMPAIGN_NAME)
        // The applicant is a CREATOR, and actor_user_id is a users-table key, so
        // the name travels in the data bag and the actor stays null.
        ->and($row->actor_user_id)->toBeNull()
        ->and($row->subject_type)->toBe(CampaignApplication::class)
        ->and($row->subject_id)->toBe(
            CampaignApplication::withoutGlobalScope(BelongsToAgencyScope::class)->firstOrFail()->id,
        );
});

it('queues the localized mail to each notifiable member when the flag is ON', function (): void {
    Mail::fake();
    Feature::activate(ApplicationNotificationsEnabled::NAME);
    $s = submittedFixture();

    $s['admin']->forceFill(['preferred_language' => 'fr'])->save();

    $this->actingAs($s['fixture']->user)->postJson($s['fixture']->applyUrl())->assertCreated();

    Mail::assertQueued(ApplicationSubmittedMail::class, 2);
    Mail::assertQueued(
        ApplicationSubmittedMail::class,
        fn (ApplicationSubmittedMail $mail): bool => $mail->hasTo($s['admin']->email) && $mail->locale === 'fr',
    );
    Mail::assertNotQueued(
        ApplicationSubmittedMail::class,
        fn (ApplicationSubmittedMail $mail): bool => $mail->hasTo($s['staff']->email),
    );
});

it('FLAG OFF: queues nothing, and still writes the in-app rows', function (): void {
    Mail::fake();
    // Default state, asserted rather than assumed — the flag ships OFF.
    expect(Feature::active(ApplicationNotificationsEnabled::NAME))->toBeFalse();

    $s = submittedFixture();

    $this->actingAs($s['fixture']->user)->postJson($s['fixture']->applyUrl())->assertCreated();

    // BREAK-REVERT ANCHOR (§5.35): remove the Feature::active() guard in
    // CampaignApplicationNotifier::queue() and this is the test that reddens.
    Mail::assertNothingQueued();

    // The flag gates MAIL. In-app is not the fan-out risk and keeps working, so
    // an agency loses nothing operationally while mail is dark.
    expect(Notification::query()->where('type', NotificationType::CampaignApplicationSubmitted->value)->count())
        ->toBe(2);
});

it('a member who silenced the type in-app gets no row — the flag does not override a preference', function (): void {
    Mail::fake();
    Feature::activate(ApplicationNotificationsEnabled::NAME);
    $s = submittedFixture();

    NotificationPreference::query()->create([
        'user_id' => $s['manager']->id,
        'type' => NotificationType::CampaignApplicationSubmitted->value,
        'channel' => NotificationChannel::InApp->value,
        'is_enabled' => false,
    ]);

    $this->actingAs($s['fixture']->user)->postJson($s['fixture']->applyUrl())->assertCreated();

    $recipients = Notification::query()
        ->where('type', NotificationType::CampaignApplicationSubmitted->value)
        ->pluck('recipient_user_id')
        ->all();

    expect($recipients)->toBe([$s['admin']->id]);

    // …and the mail leg is independent of the in-app preference: the email
    // channel has never been preference-gated platform-wide (the AH-056
    // tech-debt entry), so the manager still receives the mail. Recorded here so
    // the limitation is a pinned fact rather than a surprise.
    Mail::assertQueued(
        ApplicationSubmittedMail::class,
        fn (ApplicationSubmittedMail $mail): bool => $mail->hasTo($s['manager']->email),
    );
});

it('an agency with no notifiable members is a silent no-op, not an error', function (): void {
    Mail::fake();
    Feature::activate(ApplicationNotificationsEnabled::NAME);
    $fixture = CreatorJobFixture::make(); // no agency users at all

    $this->actingAs($fixture->user)->postJson($fixture->applyUrl())->assertCreated();

    Mail::assertNothingQueued();
    expect(Notification::query()->count())->toBe(0);
});
