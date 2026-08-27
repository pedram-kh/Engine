<?php

declare(strict_types=1);

use App\Modules\Admin\Enums\AutomationEvent;
use App\Modules\Admin\Jobs\RunAutomationRuleJob;
use App\Modules\Admin\Models\AdminAutomationRule;
use App\Modules\Agencies\Mail\AdminConnectedMail;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Features\PlatformAutomationsEnabled;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-090 (D4) — the on-approval hook.
|--------------------------------------------------------------------------
|
| Two halves, deliberately separate:
|
|   - the DISPATCH contract (Queue::fake) — one job per enabled rule watching
|     this event, and nothing at all when the approval itself did not happen;
|   - the END-TO-END path (real sync queue) — approve over real HTTP, the job
|     runs, a relation exists. This is the leg that carries what no Playwright
|     spec covers this chunk (D7).
|
| RunAutomationRuleJobTest owns the fire's own decisions; this file owns whether
| and when it is asked to make them.
*/

function apprAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

function apprPendingCreator(): Creator
{
    $user = User::factory()->create(['type' => UserType::Creator, 'preferred_language' => 'en']);

    return CreatorFactory::new()->kycVerified()->createOne([
        'user_id' => $user->id,
        'application_status' => ApplicationStatus::Pending->value,
    ]);
}

function apprUrl(Creator $creator): string
{
    return "/api/v1/admin/creators/{$creator->ulid}/approve";
}

function apprRelation(Agency $agency, Creator $creator): ?AgencyCreatorRelation
{
    return AgencyCreatorRelation::query()->withoutGlobalScopes()
        ->where('agency_id', $agency->id)->where('creator_id', $creator->id)->first();
}

/** A rule row watching an event this version's enum does not know. */
function apprForeignEventRule(string $event, string $agencyUlid): void
{
    DB::table('admin_automation_rules')->insert([
        'ulid' => (string) Str::ulid(),
        'name' => "Watches {$event}",
        'event' => $event,
        'action' => 'connect_to_agency',
        'config' => json_encode(['agency_id' => $agencyUlid]),
        'enabled' => true,
        'fire_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ---------------------------------------------------------------------------
// The dispatch contract
// ---------------------------------------------------------------------------

it('dispatches nothing when no rule watches the event (the shipped state)', function (): void {
    Queue::fake();

    $this->actingAs(apprAdmin(), 'web_admin')->postJson(apprUrl(apprPendingCreator()))->assertOk();

    // The table ships empty and this is what that means: an approval on deploy
    // day costs one extra indexed read and nothing else.
    Queue::assertNotPushed(RunAutomationRuleJob::class);
});

it('dispatches one job per ENABLED rule watching creator.approved', function (): void {
    Queue::fake();
    $agencyA = Agency::factory()->createOne();
    $agencyB = Agency::factory()->createOne();
    $ruleA = AdminAutomationRule::factory()->connectTo($agencyA)->createOne();
    $ruleB = AdminAutomationRule::factory()->connectTo($agencyB)->createOne();
    AdminAutomationRule::factory()->connectTo($agencyA)->disabled()->createOne();

    $creator = apprPendingCreator();
    $this->actingAs(apprAdmin(), 'web_admin')->postJson(apprUrl($creator))->assertOk();

    Queue::assertPushed(RunAutomationRuleJob::class, 2);

    // IDs only — a serialized model re-resolves to null in a tenant-less worker
    // (the documented trap), so the payload is checked, not assumed.
    foreach ([$ruleA, $ruleB] as $rule) {
        Queue::assertPushed(
            RunAutomationRuleJob::class,
            fn (RunAutomationRuleJob $job): bool => (new ReflectionProperty($job, 'ruleId'))->getValue($job) === $rule->id
                && (new ReflectionProperty($job, 'creatorId'))->getValue($job) === $creator->id,
        );
    }
});

it('ignores rules watching a different event', function (): void {
    Queue::fake();
    $agency = Agency::factory()->createOne();

    // Inserted at the DB level on purpose. `AutomationEvent` has ONE case in v1,
    // so a row watching another event cannot be manufactured through the model —
    // which is also exactly what a row written by a later version of the app, or
    // by hand, looks like to today's code.
    apprForeignEventRule('kyc.verified', $agency->ulid);
    apprForeignEventRule('creator.rejected', $agency->ulid);
    AdminAutomationRule::factory()->connectTo($agency)->createOne();

    $this->actingAs(apprAdmin(), 'web_admin')->postJson(apprUrl(apprPendingCreator()))->assertOk();

    // Break-revert: drop the `event` filter from dispatchApprovalAutomations()
    // and this reads 3.
    Queue::assertPushed(RunAutomationRuleJob::class, 1);
});

it('dispatches nothing when the approval was REFUSED', function (): void {
    Queue::fake();
    $agency = Agency::factory()->createOne();
    AdminAutomationRule::factory()->connectTo($agency)->createOne();

    // Identity not cleared — the KYC gate refuses before any write.
    $creator = CreatorFactory::new()->createOne([
        'application_status' => ApplicationStatus::Pending->value,
        'kyc_status' => KycStatus::None->value,
    ]);

    $this->actingAs(apprAdmin(), 'web_admin')->postJson(apprUrl($creator))->assertStatus(422);

    Queue::assertNotPushed(RunAutomationRuleJob::class);
});

it('dispatches nothing on a 409 re-approval of an already-approved creator', function (): void {
    Queue::fake();
    $agency = Agency::factory()->createOne();
    AdminAutomationRule::factory()->connectTo($agency)->createOne();
    $creator = CreatorFactory::new()->kycVerified()->createOne([
        'application_status' => ApplicationStatus::Approved->value,
        'approved_at' => now()->subDay(),
    ]);

    $this->actingAs(apprAdmin(), 'web_admin')->postJson(apprUrl($creator))->assertStatus(409);

    // The idempotency guard is upstream of the hook: no transition, no fire.
    Queue::assertNotPushed(RunAutomationRuleJob::class);
});

// ---------------------------------------------------------------------------
// The after-commit seam
// ---------------------------------------------------------------------------

it('dispatches AFTER the approval transaction commits', function (): void {
    Mail::fake();
    Feature::activate(PlatformAutomationsEnabled::NAME);
    $agency = Agency::factory()->createOne();
    AdminAutomationRule::factory()->connectTo($agency)->createOne();
    $creator = apprPendingCreator();

    $levelsAtWrite = [];
    AgencyCreatorRelation::created(static function () use (&$levelsAtWrite): void {
        $levelsAtWrite[] = DB::transactionLevel();
    });

    $this->actingAs(apprAdmin(), 'web_admin')->postJson(apprUrl($creator))->assertOk();

    /*
     * BREAK-REVERT ANCHOR (§5.35): move the dispatchApprovalAutomations() call
     * inside approve()'s DB::transaction() and this reddens.
     *
     * The nesting depth when the relation is written is a direct readout of
     * where the dispatch sits, because the test queue is `sync` and the job runs
     * inline at the dispatch site: 1 is RefreshDatabase's own transaction, +1 is
     * the connect service's. A dispatch from inside approve()'s transaction
     * would make this 3.
     *
     * Why it matters beyond tidiness: every queue connection is configured
     * `after_commit => false` (config/queue.php), so in production an
     * in-transaction dispatch is visible to a worker BEFORE the commit — the job
     * re-reads a creator who is still `pending` and logs
     * `skipped:creator_not_approved`. The feature would fail silently and
     * legibly-wrongly, which is worse than failing loudly.
     */
    expect($levelsAtWrite)->toBe([2]);
});

// ---------------------------------------------------------------------------
// End to end — real endpoint, real job, real relation
// ---------------------------------------------------------------------------

it('connects the creator end-to-end: approve over HTTP, rule fires, roster row exists', function (): void {
    Mail::fake();
    Feature::activate(PlatformAutomationsEnabled::NAME);
    $agency = Agency::factory()->createOne(['name' => 'Northwind Talent']);
    $rule = AdminAutomationRule::factory()->connectTo($agency)->createOne();
    $creator = apprPendingCreator();

    $this->actingAs(apprAdmin(), 'web_admin')->postJson(apprUrl($creator))->assertOk();

    expect(apprRelation($agency, $creator)?->relationship_status)->toBe(RelationshipStatus::Roster)
        ->and(apprRelation($agency, $creator)?->invited_by_user_id)->toBeNull();

    $audit = AuditLog::query()->where('action', AuditAction::AgencyCreatorRelationAdminConnected->value)->firstOrFail();
    expect($audit->actor_type)->toBe('system')
        ->and($audit->reason)->toContain("rule #{$rule->id}");

    expect($rule->refresh()->fire_count)->toBe(1);
    Mail::assertQueued(AdminConnectedMail::class, 1);
});

it('does nothing end-to-end while the kill switch is off, even with an enabled rule', function (): void {
    Mail::fake();
    // Default state. Stated as an assertion rather than assumed, because "it
    // ships dark" is the whole deploy-safety claim.
    expect(Feature::active(PlatformAutomationsEnabled::NAME))->toBeFalse();

    $agency = Agency::factory()->createOne();
    $rule = AdminAutomationRule::factory()->connectTo($agency)->createOne();
    $creator = apprPendingCreator();

    $this->actingAs(apprAdmin(), 'web_admin')->postJson(apprUrl($creator))->assertOk();

    expect(apprRelation($agency, $creator))->toBeNull()
        ->and($rule->refresh()->fire_count)->toBe(0);
    Mail::assertNotQueued(AdminConnectedMail::class);
});

it('survives approve → reopen → re-approve with ONE relation and one audit row', function (): void {
    Mail::fake();
    Feature::activate(PlatformAutomationsEnabled::NAME);
    $admin = apprAdmin();
    $agency = Agency::factory()->createOne();
    $rule = AdminAutomationRule::factory()->connectTo($agency)->createOne();
    $creator = apprPendingCreator();

    $this->actingAs($admin, 'web_admin')->postJson(apprUrl($creator))->assertOk();

    // Back to pending and approved again — a sequence an operator can produce by
    // hand in a minute. The hook fires a second time by design (D4); the connect
    // operation's idempotency is what makes that safe.
    $creator->refresh()->forceFill([
        'application_status' => ApplicationStatus::Pending->value,
        'approved_at' => null,
        'approved_by_user_id' => null,
    ])->save();

    $this->actingAs($admin, 'web_admin')->postJson(apprUrl($creator->refresh()))->assertOk();

    expect(AgencyCreatorRelation::query()->withoutGlobalScopes()->where('creator_id', $creator->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AuditAction::AgencyCreatorRelationAdminConnected->value)->count())->toBe(1)
        // The second fire did something: it declined to act, and said so.
        ->and($rule->refresh()->fire_count)->toBe(1);

    Mail::assertQueued(AdminConnectedMail::class, 1);
});

it('does not re-connect a pair an admin deliberately disconnected (Q3, end to end)', function (): void {
    Mail::fake();
    Feature::activate(PlatformAutomationsEnabled::NAME);
    $agency = Agency::factory()->createOne();
    AdminAutomationRule::factory()->connectTo($agency)->createOne();
    $creator = apprPendingCreator();

    // The relationship was severed before this approval — an `ended` row with a
    // human decision behind it.
    AgencyCreatorRelation::factory()->ended()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
    ]);

    $this->actingAs(apprAdmin(), 'web_admin')->postJson(apprUrl($creator))->assertOk();

    expect(apprRelation($agency, $creator)?->relationship_status)->toBe(RelationshipStatus::Ended);
    Mail::assertNotQueued(AdminConnectedMail::class);
});

it('the approval itself is untouched by the hook — event, mail and audit unchanged', function (): void {
    Mail::fake();
    $agency = Agency::factory()->createOne();
    AdminAutomationRule::factory()->connectTo($agency)->createOne();
    $creator = apprPendingCreator();
    $admin = apprAdmin();

    $this->actingAs($admin, 'web_admin')->postJson(apprUrl($creator))->assertOk()
        ->assertJsonPath('data.attributes.application_status', 'approved');

    // A hook that changed the transition it hangs off would be a regression in
    // the AH-090 chunk's most-used endpoint, not a feature.
    $fresh = $creator->fresh();
    expect($fresh?->application_status)->toBe(ApplicationStatus::Approved)
        ->and($fresh?->approved_by_user_id)->toBe($admin->id)
        ->and(AuditLog::query()->where('action', AuditAction::CreatorApproved->value)->count())->toBe(1);
});

it('the rule the hook reads is the one whose event matches the transition', function (): void {
    Queue::fake();
    $agency = Agency::factory()->createOne();
    $rule = AdminAutomationRule::factory()->connectTo($agency)->createOne();

    expect($rule->event)->toBe(AutomationEvent::CreatorApproved);

    $this->actingAs(apprAdmin(), 'web_admin')->postJson(apprUrl(apprPendingCreator()))->assertOk();

    Queue::assertPushed(RunAutomationRuleJob::class, 1);
});
