<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Mail\PostVerifiedMail;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Features\PostVerifiedMailEnabled;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-101 (⑦) — the creator's "your post was verified" notice
 * (`assignment.live_verified`), the success branch of verification that used to
 * be silent.
 *
 * Pinned here:
 *   - the in-app row is UNGATED (flag off, row still written) — the dual-emit
 *     break-revert anchor;
 *   - the MAIL leg is behind `post_verified_mail_enabled` (default OFF) and then
 *     the recipient's own `email` preference;
 *   - the notice is creator-only (Q5) and a system-driven transition (null
 *     actor — the job drives it) resolves cleanly;
 *   - the mailable renders for real in all 24 locales.
 */

/**
 * @return array{creator: Creator, creatorUser: User, agencyAdmin: User, assignment: CampaignAssignment}
 */
function postVerifiedSetup(): array
{
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne([
        'agency_id' => $agency->id,
        'brand_id' => $brand->id,
        'name' => 'Autumn UGC push',
    ]);
    $inviter = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->approved()->createOne();

    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::LiveVerified)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $brand->id,
        'creator_id' => $creator->id,
        'invited_by_user_id' => $inviter->id,
    ]);

    return [
        'creator' => $creator,
        'creatorUser' => User::query()->findOrFail($creator->user_id),
        'agencyAdmin' => $inviter,
        'assignment' => $assignment,
    ];
}

function fireLiveVerified(CampaignAssignment $assignment): void
{
    event(new AssignmentTransitioned(
        assignment: $assignment,
        from: AssignmentStatus::Posted,
        to: AssignmentStatus::LiveVerified,
        action: AuditAction::AssignmentLiveVerified,
        triggeredByUserId: null,
    ));
}

function postVerifiedRows(User $user): int
{
    return Notification::query()
        ->where('recipient_user_id', $user->id)
        ->where('type', NotificationType::AssignmentLiveVerified->value)
        ->count();
}

it('FLAG OFF (the default): the in-app row writes, no mail queues (break-revert anchor)', function (): void {
    Mail::fake();
    expect(Feature::active(PostVerifiedMailEnabled::NAME))->toBeFalse();
    $s = postVerifiedSetup();

    fireLiveVerified($s['assignment']);

    expect(postVerifiedRows($s['creatorUser']))->toBe(1);
    Mail::assertNothingQueued();
});

it('FLAG ON: queues PostVerifiedMail to the creator, localized, alongside the in-app row', function (): void {
    Mail::fake();
    Feature::activate(PostVerifiedMailEnabled::NAME);
    $s = postVerifiedSetup();
    $s['creatorUser']->forceFill(['preferred_language' => 'de'])->save();

    fireLiveVerified($s['assignment']);

    Mail::assertQueued(
        PostVerifiedMail::class,
        fn (PostVerifiedMail $mail): bool => $mail->hasTo($s['creatorUser']->email)
            && $mail->locale === 'de'
            && $mail->campaignName === 'Autumn UGC push'
            && $mail->assignmentUlid === $s['assignment']->ulid,
    );
    Mail::assertQueued(PostVerifiedMail::class, 1);
    expect(postVerifiedRows($s['creatorUser']))->toBe(1);
});

it('is creator-only — the agency inviter gets neither a row nor a mail (Q5)', function (): void {
    Mail::fake();
    Feature::activate(PostVerifiedMailEnabled::NAME);
    $s = postVerifiedSetup();

    fireLiveVerified($s['assignment']);

    expect(postVerifiedRows($s['agencyAdmin']))->toBe(0);
    Mail::assertNotQueued(PostVerifiedMail::class, fn (PostVerifiedMail $m): bool => $m->hasTo($s['agencyAdmin']->email));
});

it('FLAG ON + the creator opted out of email: the mail stops, the in-app row still writes', function (): void {
    Mail::fake();
    Feature::activate(PostVerifiedMailEnabled::NAME);
    $s = postVerifiedSetup();
    NotificationPreference::factory()
        ->ofType(NotificationType::AssignmentLiveVerified)
        ->channel(NotificationChannel::Email)
        ->disabled()
        ->createOne(['user_id' => $s['creatorUser']->id]);

    fireLiveVerified($s['assignment']);

    Mail::assertNothingQueued();
    expect(postVerifiedRows($s['creatorUser']))->toBe(1);
});

it('FLAG ON + the creator opted out of in-app: the row stops, the mail still queues', function (): void {
    Mail::fake();
    Feature::activate(PostVerifiedMailEnabled::NAME);
    $s = postVerifiedSetup();
    NotificationPreference::factory()
        ->ofType(NotificationType::AssignmentLiveVerified)
        ->channel(NotificationChannel::InApp)
        ->disabled()
        ->createOne(['user_id' => $s['creatorUser']->id]);

    fireLiveVerified($s['assignment']);

    Mail::assertQueued(PostVerifiedMail::class, 1);
    expect(postVerifiedRows($s['creatorUser']))->toBe(0);
});

it('a different type\'s email opt-out does not stop this mail (the row is per type)', function (): void {
    Mail::fake();
    Feature::activate(PostVerifiedMailEnabled::NAME);
    $s = postVerifiedSetup();
    NotificationPreference::factory()
        ->ofType(NotificationType::AssignmentManuallyVerified)
        ->channel(NotificationChannel::Email)
        ->disabled()
        ->createOne(['user_id' => $s['creatorUser']->id]);

    fireLiveVerified($s['assignment']);

    Mail::assertQueued(PostVerifiedMail::class, 1);
});

// ── Real-render tests (the AH-068 house standard) ─────────────────────────

/**
 * @return array{subject: string, body: string}
 */
function renderPostVerified(PostVerifiedMail $mail, string $locale): array
{
    $previous = App::getLocale();
    App::setLocale($locale);

    try {
        return [
            'subject' => $mail->envelope()->subject ?? '',
            'body' => (string) $mail->render(),
        ];
    } finally {
        App::setLocale($previous);
    }
}

function postVerifiedMail(): PostVerifiedMail
{
    return new PostVerifiedMail(
        creatorName: 'Maria',
        campaignName: 'Autumn UGC push',
        assignmentUlid: '01JASSIGNMENTULID',
    );
}

it('renders the subject, the body and the assignment deep link', function (): void {
    $rendered = renderPostVerified(postVerifiedMail(), 'en');

    expect($rendered['subject'])->toBe('Your post for Autumn UGC push was verified')
        ->and($rendered['body'])->toContain('Maria')
        ->and($rendered['body'])->toContain('Autumn UGC push')
        ->and($rendered['body'])->toContain('has been verified')
        ->and($rendered['body'])->toContain('/creator/assignments/01JASSIGNMENTULID');
});

it('never promises payment or approval — it claims only that the post was found', function (): void {
    $body = strtolower(renderPostVerified(postVerifiedMail(), 'en')['body']);

    expect($body)->not->toContain('paid')
        ->and($body)->not->toContain('payment')
        ->and($body)->not->toContain('approved');
});

it('renders a translated subject and body in every one of the 23 non-English locales', function (): void {
    $mail = postVerifiedMail();
    $en = renderPostVerified($mail, 'en');

    foreach (['bg', 'cs', 'da', 'de', 'el', 'es', 'et', 'fi', 'fr', 'ga', 'hr', 'hu', 'it', 'lt', 'lv', 'mt', 'nl', 'pl', 'pt', 'ro', 'sk', 'sl', 'sv'] as $locale) {
        $translated = renderPostVerified($mail, $locale);

        expect($translated['subject'])->not->toBe($en['subject'], "locale {$locale} rendered the English subject")
            ->and($translated['body'])->not->toBe($en['body'], "locale {$locale} rendered the English body")
            ->and($translated['body'])->toContain('Autumn UGC push')
            ->and($translated['body'])->toContain('Maria')
            ->and($translated['body'])->toContain('/creator/assignments/01JASSIGNMENTULID');
    }
});
