<?php

declare(strict_types=1);

use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Erasure\Cancellation\CancelOpenAssignments;
use App\Modules\Erasure\Eligibility\ErasureEligibility;
use App\Modules\Erasure\Guards\DeliveredWorkGuard;
use App\Modules\Erasure\Guards\PaymentGuard;
use App\Modules\Erasure\Guards\Refusals;
use App\Modules\Erasure\Selection\DryRun;
use App\Modules\Erasure\Selection\Selections;
use App\Modules\Erasure\Subject\SubjectSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ErasureGraph;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-104c C1-4 — delivered work refuses an erasure, and is never cancelled
|--------------------------------------------------------------------------
|
| `live_verified`, `manually_verified` and `completed_on_approval` are work the creator delivered
| and an agency may owe for. They refuse in the guard, in the request-time eligibility, and in the
| claim (through `Refusals`); and the rider's selection leaves them alone even if a guard failed.
*/

/** The seeded graph has open assignments; park every one in $status so the guard has one thing to see. */
function deliveredSubject(AssignmentStatus $status, string $tag = 'a'): ErasureGraph
{
    $graph = ErasureGraph::seedSubject($tag);
    DB::table('campaign_assignments')->where('creator_id', $graph->creator->id)->update(['status' => $status->value]);

    return $graph;
}

it('refuses every payment-eligible assignment state, naming the table and the states and never a person', function (AssignmentStatus $status): void {
    $graph = deliveredSubject($status);
    ErasureGraph::seedSubject('b'); // a bystander in the same state must not be counted
    DB::table('campaign_assignments')->where('creator_id', '!=', $graph->creator->id)->update(['status' => $status->value]);

    $reasons = (new DeliveredWorkGuard)->refusals(SubjectSet::resolve($graph->user));

    expect($reasons)->toHaveCount(1)
        ->and($reasons[0])->toContain('campaign_assignments')->toContain('delivered work')->toContain($status->value)
        ->and($reasons[0])->not->toContain($graph->email)
        ->and($reasons[0])->not->toContain($graph->user->name);
})->with([
    'live verified' => [AssignmentStatus::LiveVerified],
    'manually verified' => [AssignmentStatus::ManuallyVerified],
    'completed on approval (Question C1)' => [AssignmentStatus::CompletedOnApproval],
]);

it('counts only the subject\'s own assignments', function (): void {
    $graph = ErasureGraph::seedSubject('a');
    $other = ErasureGraph::seedSubject('b');
    DB::table('campaign_assignments')->where('creator_id', $other->creator->id)->update(['status' => AssignmentStatus::LiveVerified->value]);

    expect((new DeliveredWorkGuard)->refusals(SubjectSet::resolve($graph->user)))->toBe([]);
});

it('does not refuse a creator whose open and finished assignments carry no delivered work', function (string $state): void {
    $graph = deliveredSubject(AssignmentStatus::from($state));

    expect((new DeliveredWorkGuard)->refusals(SubjectSet::resolve($graph->user)))->toBe([]);
})->with(['invited', 'accepted', 'producing', 'draft_submitted', 'posted', 'approved', 'declined', 'rejected', 'cancelled']);

it('counts a soft-deleted assignment too, as the payment guard does', function (): void {
    $graph = deliveredSubject(AssignmentStatus::LiveVerified);
    DB::table('campaign_assignments')->where('creator_id', $graph->creator->id)->update(['deleted_at' => now()]);

    expect((new DeliveredWorkGuard)->refusals(SubjectSet::resolve($graph->user)))->toHaveCount(1);
});

it('is the set of payment-eligible states, read from the predicate and not typed out (a new payable state refuses by default)', function (): void {
    $payable = array_values(array_map(
        fn (AssignmentStatus $s): string => $s->value,
        array_filter(AssignmentStatus::cases(), fn (AssignmentStatus $s): bool => $s->isPaymentEligible()),
    ));

    expect(DeliveredWorkGuard::states())->toBe($payable)
        ->and($payable)->toEqualCanonicalizing(['live_verified', 'manually_verified', 'completed_on_approval'])
        ->and(array_intersect(DeliveredWorkGuard::states(), PaymentGuard::knownPaymentStates()))->toBe([]);
});

it('reaches the engine\'s refusals, the request-time eligibility and the claim through the one list', function (): void {
    $graph = deliveredSubject(AssignmentStatus::ManuallyVerified);
    $subject = SubjectSet::resolve($graph->user);

    expect(Refusals::for($subject))->toHaveCount(1)
        ->and(Refusals::for($subject)[0])->toContain('delivered work')
        ->and((new ErasureEligibility)->blockers($graph->user))->toBe([ErasureEligibility::DELIVERED_WORK]);
});

it('keeps the coarse codes apart: delivered work is not "payment"', function (): void {
    $graph = deliveredSubject(AssignmentStatus::LiveVerified);
    expect((new ErasureEligibility)->blockers($graph->user))->toBe(['delivered_work']);

    DB::table('campaign_assignments')->where('creator_id', $graph->creator->id)->limit(1)->update(['status' => AssignmentStatus::PaymentHeld->value]);

    expect((new ErasureEligibility)->blockers($graph->user))->toBe(['payment', 'delivered_work']);
});

it('leaves delivered work out of the rider\'s selection, and the dry-run count agrees with what is cancelled', function (): void {
    $graph = ErasureGraph::seedSubject('a');
    $open = DB::table('campaign_assignments')->where('creator_id', $graph->creator->id)->orderBy('id')->pluck('id')->all();
    expect(count($open))->toBeGreaterThanOrEqual(2);

    // One delivered, one open (the rest finished): only the open one is the rider's.
    DB::table('campaign_assignments')->where('creator_id', $graph->creator->id)->update(['status' => AssignmentStatus::Cancelled->value]);
    DB::table('campaign_assignments')->where('id', $open[0])->update(['status' => AssignmentStatus::LiveVerified->value]);
    DB::table('campaign_assignments')->where('id', $open[1])->update(['status' => AssignmentStatus::Accepted->value]);
    $subject = SubjectSet::resolve($graph->user);

    $selected = Selections::find(Selections::CANCEL_ASSIGNMENTS)->builder($subject, DB::connection())->pluck('id')->map(fn ($id): int => (int) $id)->all();
    $counted = collect((new DryRun)->run($subject)->lines)->firstWhere('id', Selections::CANCEL_ASSIGNMENTS)['count'] ?? null;

    expect($selected)->toBe([$open[1]])->and($counted)->toBe(1);

    $cancelled = app(CancelOpenAssignments::class)->run($subject);

    expect($cancelled)->toBe(1)
        ->and(CampaignAssignment::query()->withoutGlobalScopes()->whereKey($open[0])->value('status'))->toBe(AssignmentStatus::LiveVerified)
        ->and(CampaignAssignment::query()->withoutGlobalScopes()->whereKey($open[1])->value('status'))->toBe(AssignmentStatus::Cancelled);
});

it('does not cancel delivered work even when the selection is handed it (the re-read under the lock)', function (): void {
    $graph = ErasureGraph::seedSubject('a');
    $id = (int) DB::table('campaign_assignments')->where('creator_id', $graph->creator->id)->orderBy('id')->value('id');
    DB::table('campaign_assignments')->where('creator_id', $graph->creator->id)->update(['status' => AssignmentStatus::Cancelled->value]);
    DB::table('campaign_assignments')->where('id', $id)->update(['status' => AssignmentStatus::Accepted->value]);
    $subject = SubjectSet::resolve($graph->user);

    // The assignment becomes delivered after the selection was made but before its lock is taken.
    $ids = Selections::find(Selections::CANCEL_ASSIGNMENTS)->builder($subject, DB::connection())->pluck('id');
    expect($ids->all())->toHaveCount(1);
    DB::table('campaign_assignments')->where('id', $id)->update(['status' => AssignmentStatus::ManuallyVerified->value]);

    // The rider reads the selection itself, so it sees the new state at once; the lock re-read is the second line.
    expect(app(CancelOpenAssignments::class)->run($subject))->toBe(0)
        ->and(CampaignAssignment::query()->withoutGlobalScopes()->whereKey($id)->value('status'))->toBe(AssignmentStatus::ManuallyVerified);
});

/*
| Total classification (P5): every assignment state is, on purpose, one of three things. A state added
| later fails here until a person says which, so erasure can never quietly cancel or ignore a payable one.
*/

it('classifies every assignment state as cancelled by the rider, refusing erasure, or terminal and left alone', function (): void {
    $classes = [
        // open, not delivered: the rider cancels it
        'invited' => 'cancelled_by_rider', 'countered' => 'cancelled_by_rider', 'accepted' => 'cancelled_by_rider',
        'contracted' => 'cancelled_by_rider', 'producing' => 'cancelled_by_rider', 'draft_submitted' => 'cancelled_by_rider',
        'revision_requested' => 'cancelled_by_rider', 'approved' => 'cancelled_by_rider', 'posted' => 'cancelled_by_rider',
        // money held or paid, or delivered work: refuses
        'payment_held' => 'refuses', 'payment_released' => 'refuses',
        'live_verified' => 'refuses', 'manually_verified' => 'refuses', 'completed_on_approval' => 'refuses',
        // terminal with nothing owed: left alone
        'declined' => 'left_alone', 'rejected' => 'left_alone', 'cancelled' => 'left_alone',
    ];
    $all = array_map(fn (AssignmentStatus $s): string => $s->value, AssignmentStatus::cases());

    expect(array_keys($classes))->toEqualCanonicalizing($all, 'A new AssignmentStatus must be classified here: what does erasure do about it?');

    // The real selection and the real guards decide, not a model of them: seed one assignment in each
    // state and ask both what they do with it.
    $graph = ErasureGraph::seedSubject('a');
    $subject = SubjectSet::resolve($graph->user);
    $row = (int) DB::table('campaign_assignments')->where('creator_id', $graph->creator->id)->orderBy('id')->value('id');

    foreach (AssignmentStatus::cases() as $status) {
        DB::table('campaign_assignments')->where('creator_id', $graph->creator->id)->update(['status' => AssignmentStatus::Cancelled->value, 'payment_id' => null]);
        DB::table('campaign_assignments')->where('id', $row)->update(['status' => $status->value]);

        $picked = Selections::find(Selections::CANCEL_ASSIGNMENTS)->builder($subject, DB::connection())->pluck('id')->map(fn ($id): int => (int) $id)->all() === [$row];
        $refused = Refusals::for($subject) !== [];

        expect($picked)->toBe($classes[$status->value] === 'cancelled_by_rider', "{$status->value}: picked by the rider")
            ->and($refused)->toBe($classes[$status->value] === 'refuses', "{$status->value}: refuses erasure");
    }
});
