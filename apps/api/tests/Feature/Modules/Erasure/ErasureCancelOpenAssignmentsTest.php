<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use App\Modules\Erasure\Cancellation\CancelOpenAssignments;
use App\Modules\Erasure\Enums\ErasureLedgerState;
use App\Modules\Erasure\Execution\AuditScrub;
use App\Modules\Erasure\Execution\ErasureEngine;
use App\Modules\Erasure\Files\MediaDiskObjectStore;
use App\Modules\Erasure\Files\ObjectStore;
use App\Modules\Erasure\Models\ErasureLedger;
use App\Modules\Erasure\Registry\Disposition;
use App\Modules\Erasure\Registry\ErasureRegistry;
use App\Modules\Erasure\Run\ErasureRun;
use App\Modules\Erasure\Selection\DryRun;
use App\Modules\Erasure\Selection\Selections;
use App\Modules\Erasure\Subject\SubjectSet;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ErasureClaims;
use Tests\Support\ErasureEvents;
use Tests\Support\ErasureGraph;
use Tests\Support\FakeAuditScrub;
use Tests\Support\RecordingObjectStore;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * AH-104b-del A7 — the AH-104a rider (Q4 c). An erased creator's open assignments are
 * cancelled through the state machine, by the system, with a fixed code as the reason, and
 * each affected agency's admins and managers get one in-app notice that names no one.
 */

const CREATOR_ERASED = 'creator_account_erased';

/** @return array{agency: Agency, admin: User, manager: User, staff: User, brand: Brand} */
function cancelAgency(): array
{
    $agency = Agency::factory()->createOne();

    return [
        'agency' => $agency,
        'admin' => User::factory()->agencyAdmin($agency)->createOne(),
        'manager' => User::factory()->agencyManager($agency)->createOne(),
        'staff' => User::factory()->agencyStaff($agency)->createOne(),
        'brand' => Brand::factory()->forAgency($agency->id)->createOne(),
    ];
}

function cancelAssignment(array $agency, int $creatorId, AssignmentStatus $status, array $extra = []): CampaignAssignment
{
    $campaign = Campaign::factory()->createOne(['agency_id' => $agency['agency']->id, 'brand_id' => $agency['brand']->id]);

    return CampaignAssignment::factory()->status($status)->createOne([
        'agency_id' => $agency['agency']->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $agency['brand']->id,
        'creator_id' => $creatorId,
        'invited_by_user_id' => $agency['admin']->id,
        ...$extra,
    ]);
}

/** @return array<string, mixed> the stored row, for a byte-for-byte comparison */
function storedAssignment(CampaignAssignment $assignment): array
{
    return (array) DB::table('campaign_assignments')->where('id', $assignment->id)->first();
}

/** @return array{0: ErasureGraph, 1: ErasureRun, 2: ErasureEvents} */
function cancelRunFixture(): array
{
    Storage::fake('media');
    $events = new ErasureEvents;
    app()->instance(AuditScrub::class, new FakeAuditScrub($events));
    app()->instance(ObjectStore::class, new RecordingObjectStore(new MediaDiskObjectStore, $events));

    $graph = ErasureGraph::seedSubject('a');
    $graph->putFiles();

    return [$graph, app(ErasureRun::class), $events];
}

it('is a registry decision and a selection of its own, not a raw write the engine could run', function (): void {
    $status = ErasureRegistry::tables()['campaign_assignments']->columns['status'];
    $selection = Selections::find(Selections::CANCEL_ASSIGNMENTS);

    expect($status->disposition)->toBe(Disposition::Cancel)
        ->and($selection->disposition)->toBe(Disposition::Cancel)
        ->and($selection->stage)->toBe('4h')
        ->and(fn () => (new ErasureEngine)->run(SubjectSet::resolve(ErasureGraph::seedSubject('a')->user), null, ['4h']))
        ->toThrow(InvalidArgumentException::class);
});

it('cancels every non-terminal assignment of the creator and nothing else', function (): void {
    $a = ErasureGraph::seedSubject('a');
    $b = ErasureGraph::seedSubject('b');
    $agency = cancelAgency();

    $open = [];
    foreach ([AssignmentStatus::Invited, AssignmentStatus::Countered, AssignmentStatus::Accepted, AssignmentStatus::Producing, AssignmentStatus::DraftSubmitted, AssignmentStatus::Approved] as $status) {
        $open[$status->value] = cancelAssignment($agency, $a->creator->id, $status);
    }

    $terminal = [];
    foreach ([AssignmentStatus::Declined, AssignmentStatus::Rejected, AssignmentStatus::CompletedOnApproval, AssignmentStatus::Cancelled] as $status) {
        $terminal[$status->value] = cancelAssignment($agency, $a->creator->id, $status, $status === AssignmentStatus::Cancelled
            ? ['cancelled_reason' => 'Brand pulled the campaign', 'cancelled_by_user_id' => $agency['admin']->id, 'cancelled_at' => now()->subDay()]
            : []);
    }
    $softDeleted = cancelAssignment($agency, $a->creator->id, AssignmentStatus::Producing);
    $softDeleted->delete();
    $bystander = cancelAssignment($agency, $b->creator->id, AssignmentStatus::Producing);

    $untouched = fn (): array => array_map('storedAssignment', [...array_values($terminal), $softDeleted, $bystander]);
    $before = $untouched();
    $planned = (new DryRun)->run(SubjectSet::resolve($a->user))->counts()[Selections::CANCEL_ASSIGNMENTS];

    $cancelled = app(CancelOpenAssignments::class)->run(SubjectSet::resolve($a->user));

    expect($planned)->toBe($cancelled)
        ->and($cancelled)->toBe(count($open) + 1) // the graph's own open assignment
        ->and($untouched())->toBe($before);

    foreach ($open as $assignment) {
        $row = storedAssignment($assignment);
        expect($row['status'])->toBe('cancelled')
            ->and($row['cancelled_reason'])->toBe(CREATOR_ERASED)
            ->and($row['cancelled_by_user_id'])->toBeNull()
            ->and($row['cancelled_at'])->not->toBeNull();
    }
});

it('does it as the system through the state machine: one audit row per assignment, no actor, nothing personal', function (): void {
    $a = ErasureGraph::seedSubject('a');
    $agency = cancelAgency();
    $assignment = cancelAssignment($agency, $a->creator->id, AssignmentStatus::Producing);

    app(CancelOpenAssignments::class)->run(SubjectSet::resolve($a->user));

    $row = AuditLog::query()->where('action', AuditAction::AssignmentCancelled)->where('subject_id', $assignment->id)->sole();
    $printed = json_encode([$row->metadata, $row->reason, $row->before, $row->after, $row->actor_id, $row->actor_type], JSON_THROW_ON_ERROR);

    expect($row->actor_id)->toBeNull()
        ->and($row->reason)->toBe(CREATOR_ERASED)
        ->and((array) $row->metadata)->toMatchArray(['from' => 'producing', 'to' => 'cancelled'])
        ->and($printed)->not->toContain($a->email)
        ->and($printed)->not->toContain($a->user->name)
        ->and($printed)->not->toContain((string) $a->creator->display_name);
});

it('tells each affected agency once: admins and managers get an in-app row, staff none, and no row names the creator', function (): void {
    Mail::fake();
    Queue::fake();
    $a = ErasureGraph::seedSubject('a');
    $one = cancelAgency();
    $two = cancelAgency();
    $first = cancelAssignment($one, $a->creator->id, AssignmentStatus::Invited);
    $second = cancelAssignment($two, $a->creator->id, AssignmentStatus::Producing);
    Notification::query()->delete();

    app(CancelOpenAssignments::class)->run(SubjectSet::resolve($a->user));

    foreach ([[$one, $first], [$two, $second]] as [$agency, $assignment]) {
        $rows = Notification::query()->where('type', NotificationType::AssignmentCancelled)->where('subject_id', $assignment->id)->get();

        expect($rows->pluck('recipient_user_id')->sort()->values()->all())->toBe(collect([$agency['admin']->id, $agency['manager']->id])->sort()->values()->all())
            ->and($rows->pluck('recipient_user_id')->all())->not->toContain($agency['staff']->id);

        foreach ($rows as $row) {
            $printed = json_encode($row->toArray(), JSON_THROW_ON_ERROR);
            expect($row->actor_user_id)->toBeNull()
                ->and($row->data)->toMatchArray(['is_erased' => true, 'reason' => CREATOR_ERASED, 'assignment_ulid' => $assignment->ulid])
                ->and($row->data)->not->toHaveKeys(['creator_name', 'counterparty_name', 'sender_name'])
                ->and($printed)->not->toContain((string) $a->creator->display_name)
                ->and($printed)->not->toContain($a->user->name)
                ->and($printed)->not->toContain($a->email);
        }
    }

    // The graph's own open assignment belongs to a third agency: it is told as well, but no one gets two rows for one assignment.
    $all = Notification::query()->where('type', NotificationType::AssignmentCancelled)->get(['recipient_user_id', 'subject_id']);
    expect($all->unique(fn (Notification $n): string => $n->recipient_user_id.'-'.$n->subject_id)->count())->toBe($all->count());
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

it('sends no push for it: the notification type is not push-eligible, so no delivery row is written', function (): void {
    $a = ErasureGraph::seedSubject('a');
    $agency = cancelAgency();
    cancelAssignment($agency, $a->creator->id, AssignmentStatus::Producing);
    $deliveries = DB::table('push_deliveries')->count();

    app(CancelOpenAssignments::class)->run(SubjectSet::resolve($a->user));

    expect(DB::table('push_deliveries')->count())->toBe($deliveries);
});

it('stays quiet for every other cancellation: the agency\'s own act, and the fixed reason with a person behind it', function (): void {
    $a = ErasureGraph::seedSubject('a');
    $agency = cancelAgency();
    $own = cancelAssignment($agency, $a->creator->id, AssignmentStatus::Producing);
    $lookalike = cancelAssignment($agency, $a->creator->id, AssignmentStatus::Producing);
    $bySystem = cancelAssignment($agency, $a->creator->id, AssignmentStatus::Producing);
    Notification::query()->delete();

    app(CampaignAssignmentStateMachine::class)->cancel($own, 'Brand pulled the campaign', $agency['admin']);
    app(CampaignAssignmentStateMachine::class)->cancel($lookalike, CREATOR_ERASED, $agency['manager']);
    // No acting user either, but not the erasure's reason: some other system cancellation stays as quiet as it was.
    app(CampaignAssignmentStateMachine::class)->cancel($bySystem, 'Campaign closed by the system', null);

    expect(Notification::query()->where('type', NotificationType::AssignmentCancelled)->count())->toBe(0);
});

it('is repeatable: a second pass finds nothing left to cancel', function (): void {
    $a = ErasureGraph::seedSubject('a');
    $subject = SubjectSet::resolve($a->user);
    $step = app(CancelOpenAssignments::class);

    $first = $step->run($subject);
    $second = $step->run($subject);
    $audit = AuditLog::query()->where('action', AuditAction::AssignmentCancelled)->count();

    expect($first)->toBe(1)
        ->and($second)->toBe(0)
        ->and((new DryRun)->run($subject)->counts()[Selections::CANCEL_ASSIGNMENTS])->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::AssignmentCancelled)->count())->toBe($audit);
});

it('changes the digest when the count changes, so an approval of one state cannot run against another', function (): void {
    $a = ErasureGraph::seedSubject('a');
    $open = cancelAssignment(cancelAgency(), $a->creator->id, AssignmentStatus::Producing);
    $before = (new DryRun)->run(SubjectSet::resolve($a->user));

    // The assignment finishes by itself: the number of assignments the subject has does not move, only the number that would be cancelled.
    DB::table('campaign_assignments')->where('id', $open->id)->update(['status' => 'declined']);
    $after = (new DryRun)->run(SubjectSet::resolve($a->user));

    expect($after->subject)->toBe($before->subject)
        ->and($after->counts()[Selections::CANCEL_ASSIGNMENTS])->toBe($before->counts()[Selections::CANCEL_ASSIGNMENTS] - 1)
        ->and($after->digest())->not->toBe($before->digest());
});

it('skips an assignment that reached a terminal state after the selection, instead of failing the erasure', function (): void {
    $a = ErasureGraph::seedSubject('a');
    $agency = cancelAgency();
    $first = cancelAssignment($agency, $a->creator->id, AssignmentStatus::Producing);
    $second = cancelAssignment($agency, $a->creator->id, AssignmentStatus::Producing);
    $subject = SubjectSet::resolve($a->user);
    $planned = (new DryRun)->run($subject)->counts()[Selections::CANCEL_ASSIGNMENTS];

    // While the first one is being cancelled, someone finishes the second (the graph's own comes first by id).
    Event::listen(AssignmentTransitioned::class, function (AssignmentTransitioned $event) use ($first, $second): void {
        if ($event->assignment->id === $first->id) {
            DB::table('campaign_assignments')->where('id', $second->id)->update(['status' => 'declined']);
        }
    });

    $cancelled = app(CancelOpenAssignments::class)->run($subject);

    expect($cancelled)->toBe($planned - 1)
        ->and(storedAssignment($second)['status'])->toBe('declined')
        ->and(storedAssignment($second)['cancelled_reason'])->toBeNull();
});

it('skips an assignment that was verified after the selection: delivered work is never cancelled by the rider (AH-104c)', function (): void {
    $a = ErasureGraph::seedSubject('a');
    $agency = cancelAgency();
    $first = cancelAssignment($agency, $a->creator->id, AssignmentStatus::Producing);
    $second = cancelAssignment($agency, $a->creator->id, AssignmentStatus::Posted);
    $subject = SubjectSet::resolve($a->user);
    $planned = (new DryRun)->run($subject)->counts()[Selections::CANCEL_ASSIGNMENTS];

    // While the first is being cancelled, a verification lands on the second: it is now delivered work.
    Event::listen(AssignmentTransitioned::class, function (AssignmentTransitioned $event) use ($first, $second): void {
        if ($event->assignment->id === $first->id) {
            DB::table('campaign_assignments')->where('id', $second->id)->update(['status' => 'live_verified']);
        }
    });

    $cancelled = app(CancelOpenAssignments::class)->run($subject);

    expect($cancelled)->toBe($planned - 1)
        ->and(storedAssignment($second)['status'])->toBe('live_verified')
        ->and(storedAssignment($second)['cancelled_reason'])->toBeNull();
});

it('runs inside the erasure: after the engine\'s writes, before the closing audit pass, and its count is in the ledger', function (): void {
    [$graph, $run, $events] = cancelRunFixture();
    $open = cancelAssignment(cancelAgency(), $graph->creator->id, AssignmentStatus::Producing);
    Event::listen(AssignmentTransitioned::class, function () use ($events): void {
        $events->add('cancel');
    });
    DB::listen(function ($query) use ($events): void {
        if (preg_match('/^\s*delete from "sessions"/i', $query->sql) === 1) {
            $events->add('database');
        }
    });
    $plan = $run->plan($graph->user);

    $ledger = $run->execute($graph->user, ErasureClaims::for($graph->user), $plan->digest(), $graph->user->ulid);

    expect($events->collapsed())->toBe(['scrub', 'objects', 'database', 'cancel', 'scrub'])
        ->and($ledger->state)->toBe(ErasureLedgerState::Completed)
        ->and(data_get($ledger->counts, ['database', Selections::CANCEL_ASSIGNMENTS]))->toBe(2)
        ->and($plan->report->counts()[Selections::CANCEL_ASSIGNMENTS])->toBe(2)
        ->and(storedAssignment($open)['status'])->toBe('cancelled')
        ->and(DB::table('campaign_assignments')->where('creator_id', $graph->creator->id)->whereNotIn('status', ['cancelled', 'declined'])->count())->toBe(0);
});

it('rolls the whole erasure back when the step fails: nothing is erased, nothing cancelled', function (): void {
    [$graph, $run] = cancelRunFixture();
    $open = cancelAssignment(cancelAgency(), $graph->creator->id, AssignmentStatus::Producing);
    Event::listen(AssignmentTransitioned::class, function (): void {
        throw new RuntimeException('boom');
    });
    $plan = $run->plan($graph->user);
    $before = storedAssignment($open);

    expect(fn () => $run->execute($graph->user, ErasureClaims::for($graph->user), $plan->digest(), $graph->user->ulid))->toThrow(RuntimeException::class, 'boom');

    expect(User::withTrashed()->findOrFail($graph->user->id)->isErased())->toBeFalse()
        ->and(storedAssignment($open))->toBe($before)
        ->and(DB::table('sessions')->where('user_id', $graph->user->id)->count())->toBe(2)
        ->and(ErasureLedger::query()->where('user_ulid', $graph->user->ulid)->sole()->state)->toBe(ErasureLedgerState::Incomplete);
});

it('leaves the other creator\'s open assignments alone through a whole run', function (): void {
    [$graph, $run] = cancelRunFixture();
    $other = ErasureGraph::seedSubject('b');
    cancelAssignment(cancelAgency(), $other->creator->id, AssignmentStatus::Producing);
    $rows = fn (): array => DB::table('campaign_assignments')->where('creator_id', $other->creator->id)->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all();
    $before = $rows();
    $plan = $run->plan($graph->user);

    $run->execute($graph->user, ErasureClaims::for($graph->user), $plan->digest(), $graph->user->ulid);

    expect($rows())->toBe($before)
        ->and(collect($before)->pluck('status')->contains('cancelled'))->toBeFalse();
});
