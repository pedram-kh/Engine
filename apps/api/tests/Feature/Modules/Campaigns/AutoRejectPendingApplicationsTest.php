<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Jobs\AutoRejectPendingApplicationsJob;
use App\Modules\Campaigns\Mail\ApplicationRejectedMail;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Campaigns\Services\CampaignApplicationDecisionService;
use App\Modules\Campaigns\Services\CampaignApplicationNotifier;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Features\ApplicationNotificationsEnabled;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-058 (chunk 4, D5) — the campaign-terminal posture: a campaign that closes
 * answers the applications still waiting on it.
 *
 * Two halves, tested separately because they fail separately: the three-line
 * flip DETECTOR in `CampaignController::update()` (does the right edge dispatch,
 * and only that edge?), and the JOB (does it answer every pending row exactly
 * once, from a worker with no tenant?).
 */

/**
 * @return array{agency: Agency, brand: Brand, campaign: Campaign, admin: User}
 */
function terminalSetup(CampaignStatus $status = CampaignStatus::Active): array
{
    $agency = Agency::factory()->createOne(['name' => 'Bright Harbour']);
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->listed()->createOne([
        'agency_id' => $agency->id,
        'brand_id' => $brand->id,
        'name' => 'Autumn UGC push',
        'status' => $status,
        'budget_currency' => 'EUR',
    ]);

    return [
        'agency' => $agency,
        'brand' => $brand,
        'campaign' => $campaign,
        'admin' => User::factory()->agencyAdmin($agency)->createOne(),
    ];
}

/**
 * @return array{0: Creator, 1: User, 2: CampaignApplication}
 */
function pendingApplicant(array $s): array
{
    $user = User::factory()->creator()->createOne();
    $creator = CreatorFactory::new()->approved()->createOne(['user_id' => $user->id]);

    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $s['agency']->id,
        'creator_id' => $creator->id,
    ]);

    $application = CampaignApplication::factory()->createOne([
        'campaign_id' => $s['campaign']->id,
        'agency_id' => $s['agency']->id,
        'creator_id' => $creator->id,
    ]);

    return [$creator, $user, $application];
}

function terminalCampaignUrl(array $s): string
{
    return "/api/v1/agencies/{$s['agency']->ulid}/campaigns/{$s['campaign']->ulid}";
}

// ── The flip detector: which edges dispatch, and which must not ──────────────

it('dispatches the job on the active → cancelled edge', function (): void {
    Queue::fake();
    $s = terminalSetup();

    $this->actingAs($s['admin'])
        ->patchJson(terminalCampaignUrl($s), ['status' => 'cancelled'])
        ->assertOk();

    Queue::assertPushed(AutoRejectPendingApplicationsJob::class);
});

it('dispatches the job on the active → completed edge', function (): void {
    Queue::fake();
    $s = terminalSetup();

    $this->actingAs($s['admin'])
        ->patchJson(terminalCampaignUrl($s), ['status' => 'completed'])
        ->assertOk();

    Queue::assertPushed(AutoRejectPendingApplicationsJob::class);
});

it('dispatches NOTHING on a non-terminal update', function (string $status): void {
    Queue::fake();
    $s = terminalSetup(CampaignStatus::Draft);

    $this->actingAs($s['admin'])
        ->patchJson(terminalCampaignUrl($s), ['status' => $status])
        ->assertOk();

    // A rename, a pause, a go-live — none of these close a job, and none may
    // reach into a creator's inbox.
    Queue::assertNotPushed(AutoRejectPendingApplicationsJob::class);
})->with(['active', 'paused', 'draft']);

it('dispatches NOTHING when an already-terminal campaign is edited again', function (): void {
    Queue::fake();
    $s = terminalSetup(CampaignStatus::Cancelled);

    // The detector fires on the EDGE, so editing a cancelled campaign's name, or
    // re-sending the same cancelled status, is silent. This is the first of the
    // two idempotency guards (review priority 4).
    $this->actingAs($s['admin'])
        ->patchJson(terminalCampaignUrl($s), ['name' => 'Autumn UGC push (archived)', 'status' => 'cancelled'])
        ->assertOk();

    Queue::assertNotPushed(AutoRejectPendingApplicationsJob::class);
});

it('dispatches again on a re-close after a re-open — and the job is what makes that safe', function (): void {
    Queue::fake();
    $s = terminalSetup(CampaignStatus::Cancelled);

    $this->actingAs($s['admin'])->patchJson(terminalCampaignUrl($s), ['status' => 'active'])->assertOk();
    Queue::assertNotPushed(AutoRejectPendingApplicationsJob::class);

    $this->actingAs($s['admin'])->patchJson(terminalCampaignUrl($s), ['status' => 'cancelled'])->assertOk();

    // cancelled → active → cancelled DOES dispatch a second job, by design: the
    // detector cannot know what the first run answered. Sending nothing twice is
    // the JOB's responsibility, asserted below, because that is the only place
    // the current row states are readable.
    Queue::assertPushed(AutoRejectPendingApplicationsJob::class, 1);
});

// ── The job ─────────────────────────────────────────────────────────────────

it('rejects every pending application with cause=campaign_closed and notifies each creator', function (): void {
    Mail::fake();
    Feature::activate(ApplicationNotificationsEnabled::NAME);
    $s = terminalSetup(CampaignStatus::Cancelled);

    [, $firstUser, $first] = pendingApplicant($s);
    [, $secondUser, $second] = pendingApplicant($s);

    (new AutoRejectPendingApplicationsJob($s['campaign']->id))->handle(
        app(CampaignApplicationDecisionService::class),
        app(CampaignApplicationNotifier::class),
    );

    expect($first->fresh()?->status)->toBe(CampaignApplicationStatus::Rejected)
        ->and($first->fresh()?->responded_at)->not->toBeNull()
        ->and($second->fresh()?->status)->toBe(CampaignApplicationStatus::Rejected)
        ->and($second->fresh()?->responded_at)->not->toBeNull();

    // One type, two causes (D5): no vocabulary doubling. The cause is what lets
    // the copy say "this job closed" instead of "you were not selected".
    $rows = Notification::query()
        ->where('type', NotificationType::CampaignApplicationRejected->value)
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('recipient_user_id')->sort()->values()->all())
        ->toBe(collect([$firstUser->id, $secondUser->id])->sort()->values()->all())
        ->and($rows->every(fn (Notification $row): bool => ($row->data['cause'] ?? null) === 'campaign_closed'))->toBeTrue();

    // The audit rows carry the same cause and are honestly attributed: no human
    // pressed reject, so the actor is the system.
    $audits = AuditLog::query()->where('action', AuditAction::CampaignApplicationRejected->value)->get();

    expect($audits)->toHaveCount(2)
        ->and($audits->every(fn (AuditLog $row): bool => $row->actor_type === 'system'))->toBeTrue()
        ->and($audits->every(fn (AuditLog $row): bool => $row->actor_id === null))->toBeTrue()
        // C5: the agency comes from the application row, never from ambient
        // tenancy — a worker has none, and a null agency_id is a row no agency
        // admin will ever see.
        ->and($audits->every(fn (AuditLog $row): bool => $row->agency_id === $s['agency']->id))->toBeTrue()
        ->and($audits->every(fn (AuditLog $row): bool => ($row->metadata['cause'] ?? null) === 'campaign_closed'))->toBeTrue();

    Mail::assertQueuedCount(2);
    Mail::assertQueued(ApplicationRejectedMail::class);
});

it('IDEMPOTENT: a second run sends nothing twice and writes nothing twice', function (): void {
    Mail::fake();
    Feature::activate(ApplicationNotificationsEnabled::NAME);
    $s = terminalSetup(CampaignStatus::Cancelled);

    [, , $application] = pendingApplicant($s);

    $run = fn () => (new AutoRejectPendingApplicationsJob($s['campaign']->id))->handle(
        app(CampaignApplicationDecisionService::class),
        app(CampaignApplicationNotifier::class),
    );

    $run();
    $respondedAt = $application->fresh()?->responded_at;

    $this->travel(10)->minutes();
    $run();

    // The `pending` re-filter lives inside the job's own read, executed in the
    // worker: rows the first run answered are simply not in the second run's
    // result set. This is what makes `active → cancelled → active → cancelled`
    // safe (review priority 4).
    expect($application->fresh()?->responded_at?->toIso8601String())->toBe($respondedAt?->toIso8601String())
        ->and(Notification::query()->where('type', NotificationType::CampaignApplicationRejected->value)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AuditAction::CampaignApplicationRejected->value)->count())->toBe(1);

    Mail::assertQueuedCount(1);
});

it('leaves ALREADY-ANSWERED applications untouched', function (): void {
    Mail::fake();
    Feature::activate(ApplicationNotificationsEnabled::NAME);
    $s = terminalSetup(CampaignStatus::Completed);

    [, , $accepted] = pendingApplicant($s);
    $acceptedAt = now()->subWeek();
    $accepted->forceFill([
        'status' => CampaignApplicationStatus::Accepted,
        'responded_at' => $acceptedAt,
    ])->save();

    [, , $pending] = pendingApplicant($s);

    (new AutoRejectPendingApplicationsJob($s['campaign']->id))->handle(
        app(CampaignApplicationDecisionService::class),
        app(CampaignApplicationNotifier::class),
    );

    // An accepted applicant is engaged — they hold an assignment, and the
    // campaign closing does not un-accept their application or tell them they
    // were not selected.
    expect($accepted->fresh()?->status)->toBe(CampaignApplicationStatus::Accepted)
        ->and($accepted->fresh()?->responded_at?->toIso8601String())->toBe($acceptedAt->toIso8601String())
        ->and($pending->fresh()?->status)->toBe(CampaignApplicationStatus::Rejected);

    Mail::assertQueuedCount(1);
});

it('FLAG OFF: no mail, and the in-app rows are STILL written', function (): void {
    Mail::fake();
    Feature::deactivate(ApplicationNotificationsEnabled::NAME);
    $s = terminalSetup(CampaignStatus::Cancelled);

    [, , $application] = pendingApplicant($s);

    (new AutoRejectPendingApplicationsJob($s['campaign']->id))->handle(
        app(CampaignApplicationDecisionService::class),
        app(CampaignApplicationNotifier::class),
    );

    // The flag gates mail; the rejection and its in-app notice are database
    // truth about a closed campaign, and a mail flag must not decide whether the
    // truth gets written.
    expect($application->fresh()?->status)->toBe(CampaignApplicationStatus::Rejected)
        ->and(Notification::query()->where('type', NotificationType::CampaignApplicationRejected->value)->count())->toBe(1);

    Mail::assertNothingQueued();
});

/**
 * AH-059 (D2) — the observability gap that cost an eyes-on session.
 *
 * The reported symptom was "the campaign-cancelled auto-reject produced the
 * in-app notification but no email". The symptom was real. The attributed cause
 * — a mail-path defect on the `campaign_closed` variant — was not: the flag had
 * never been armed, so the mail leg was correctly silent, and NOTHING in any log
 * said so. Confirmed by Pedram at plan-pause: no application email was observed
 * at any point in the eyes-on session, manual reject included.
 *
 * No behaviour changed as a result. This is the test for the line that now makes
 * the silence self-explaining, so the next operator reads the answer instead of
 * reconstructing it from Pennant rows and Mailhog.
 */
it('FLAG OFF: says so in the log — the silence is legible, not mysterious', function (): void {
    Mail::fake();
    $log = Log::spy();
    Feature::deactivate(ApplicationNotificationsEnabled::NAME);
    $s = terminalSetup(CampaignStatus::Cancelled);

    [, , $application] = pendingApplicant($s);

    (new AutoRejectPendingApplicationsJob($s['campaign']->id))->handle(
        app(CampaignApplicationDecisionService::class),
        app(CampaignApplicationNotifier::class),
    );

    // BREAK-REVERT ANCHOR (§5.35): delete the logEmission() call from
    // CampaignApplicationNotifier::rejected() and this is the test that reddens.
    $log->shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context) use ($application, $s): bool {
            return $message === 'jobs-board: application notification emitted'
                && $context['type'] === NotificationType::CampaignApplicationRejected->value
                && $context['application_id'] === $application->id
                && $context['campaign_id'] === $s['campaign']->id
                // The in-app row WAS written — one recipient was reached…
                && $context['recipients'] === 1
                // …and the reason there is no email is stated, in a key whose
                // name is the answer: an operator chose this.
                && $context['mail_queued'] === 0
                && $context['mail_suppressed_by_flag'] === 1;
        })
        ->once();
});

it('FLAG ON: the same line reports the mail as queued, not suppressed', function (): void {
    Mail::fake();
    $log = Log::spy();
    Feature::activate(ApplicationNotificationsEnabled::NAME);
    $s = terminalSetup(CampaignStatus::Cancelled);

    pendingApplicant($s);

    (new AutoRejectPendingApplicationsJob($s['campaign']->id))->handle(
        app(CampaignApplicationDecisionService::class),
        app(CampaignApplicationNotifier::class),
    );

    // The counterpart assertion: the line is only useful if it DISTINGUISHES the
    // two states. `mail_suppressed_by_flag: 0` with `mail_queued: 1` is the
    // reading that sends an operator to the worker and the transport instead.
    $log->shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $message === 'jobs-board: application notification emitted'
            && $context['mail_queued'] === 1
            && $context['mail_suppressed_by_flag'] === 0)
        ->once();

    Mail::assertQueued(ApplicationRejectedMail::class, 1);
});

it('is a no-op when the campaign was RE-OPENED before the worker ran', function (): void {
    Mail::fake();
    Feature::activate(ApplicationNotificationsEnabled::NAME);
    $s = terminalSetup(CampaignStatus::Active);

    [, , $application] = pendingApplicant($s);

    // The job was enqueued by a cancel that has since been undone. Terminality
    // is re-checked against the CURRENT campaign, because the reason to reject
    // these applications no longer exists.
    (new AutoRejectPendingApplicationsJob($s['campaign']->id))->handle(
        app(CampaignApplicationDecisionService::class),
        app(CampaignApplicationNotifier::class),
    );

    expect($application->fresh()?->status)->toBe(CampaignApplicationStatus::Pending);

    Mail::assertNothingQueued();
});

it('is a no-op when the campaign no longer exists', function (): void {
    Mail::fake();
    $s = terminalSetup(CampaignStatus::Cancelled);
    $campaignId = $s['campaign']->id;
    $s['campaign']->delete();

    (new AutoRejectPendingApplicationsJob($campaignId))->handle(
        app(CampaignApplicationDecisionService::class),
        app(CampaignApplicationNotifier::class),
    );

    Mail::assertNothingQueued();
});

it('reaches the rows with NO ambient tenant — the whole point of carrying the key', function (): void {
    Mail::fake();
    Feature::activate(ApplicationNotificationsEnabled::NAME);
    $s = terminalSetup(CampaignStatus::Cancelled);

    [, , $mine] = pendingApplicant($s);

    // A second agency's campaign with its own pending application: the job must
    // find its own rows without a tenant context, and must not find anyone
    // else's. The `agency_id` match is what re-imposes the boundary the dropped
    // global scope would otherwise have provided.
    $other = terminalSetup(CampaignStatus::Cancelled);
    [, , $theirs] = pendingApplicant($other);

    (new AutoRejectPendingApplicationsJob($s['campaign']->id))->handle(
        app(CampaignApplicationDecisionService::class),
        app(CampaignApplicationNotifier::class),
    );

    expect($mine->fresh()?->status)->toBe(CampaignApplicationStatus::Rejected)
        ->and($theirs->fresh()?->status)->toBe(CampaignApplicationStatus::Pending);

    Mail::assertQueuedCount(1);
});

it('the dispatched job answers the applications end to end through update()', function (): void {
    Mail::fake();
    Feature::activate(ApplicationNotificationsEnabled::NAME);
    $s = terminalSetup();

    [, , $application] = pendingApplicant($s);

    // No Queue::fake() — the sync queue runs the job for real, so this is the
    // one test that proves the detector, the dispatch and the job compose.
    $this->actingAs($s['admin'])
        ->patchJson(terminalCampaignUrl($s), ['status' => 'cancelled'])
        ->assertOk();

    expect($application->fresh()?->status)->toBe(CampaignApplicationStatus::Rejected)
        ->and(Notification::query()->where('type', NotificationType::CampaignApplicationRejected->value)->count())->toBe(1);

    Mail::assertQueuedCount(1);
});
