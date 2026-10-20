<?php

declare(strict_types=1);

use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Erasure\Execution\ErasureEngine;
use App\Modules\Erasure\Guards\ErasureRefused;
use App\Modules\Erasure\Guards\PaymentGuard;
use App\Modules\Erasure\Guards\PaymentPolicy;
use App\Modules\Erasure\Guards\Refusals;
use App\Modules\Erasure\Subject\SubjectSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ErasureGraph;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** @return list<string> */
function paymentSchemaTables(): array
{
    return array_values(array_map(fn (array $t): string => $t['name'], Schema::getTables()));
}

/** @return list<string> */
function paymentAssignmentStatuses(): array
{
    return array_values(array_map(fn (AssignmentStatus $s): string => $s->value, AssignmentStatus::cases()));
}

it('has no payment violation in the real schema and the real assignment states', function (): void {
    expect(PaymentPolicy::violations(paymentSchemaTables(), paymentAssignmentStatuses()))->toBe([]);
});

it('fails when a payment, payout or escrow table appears without a policy', function (string $table): void {
    $problems = PaymentPolicy::violations([...paymentSchemaTables(), $table], paymentAssignmentStatuses());

    expect($problems)->toHaveCount(1)->and($problems[0])->toContain($table)->toContain('paymentPolicies');
})->with(['payments', 'escrow_holds', 'creator_payout_batches', 'agency_payment_methods']);

it('fails when a payment policy outlives its table', function (): void {
    $tables = array_values(array_filter(paymentSchemaTables(), fn (string $t): bool => $t !== 'creator_payout_methods'));
    $problems = PaymentPolicy::violations($tables, paymentAssignmentStatuses());

    expect($problems)->toHaveCount(1)->and($problems[0])->toContain('stale');
});

it('fails when a new assignment state mentions payment, or a known one disappears', function (): void {
    $added = PaymentPolicy::violations(paymentSchemaTables(), [...paymentAssignmentStatuses(), 'payment_failed']);
    $removed = PaymentPolicy::violations(paymentSchemaTables(), array_values(array_filter(paymentAssignmentStatuses(), fn (string $s): bool => $s !== 'payment_released')));

    expect($added)->toHaveCount(1)->and($added[0])->toContain('payment_failed')
        ->and($removed)->toHaveCount(1);
});

it('does not take a word that merely contains "payment" or "payout" for a financial table', function (): void {
    expect(PaymentPolicy::violations([...paymentSchemaTables(), 'prepayment_notes_unrelated'], paymentAssignmentStatuses()))->toBe([]);
});

it('knows exactly the two payment states of AssignmentStatus today', function (): void {
    expect(PaymentGuard::knownPaymentStates())->toBe(['payment_held', 'payment_released']);
});

it('allows a subject with no payment state and no payment id', function (): void {
    $graph = ErasureGraph::seedSubject('a');

    expect(Refusals::for(SubjectSet::resolve($graph->user)))->toBe([]);
});

it('refuses a subject with an assignment in a payment state or with a payment id', function (string $column, string $value, string $needle): void {
    $graph = ErasureGraph::seedSubject('a');
    $id = DB::table('campaign_assignments')->where('creator_id', $graph->creator->id)->value('id');
    DB::table('campaign_assignments')->where('id', $id)->update([$column => $value]);

    $reasons = (new PaymentGuard)->refusals(SubjectSet::resolve($graph->user));

    expect($reasons)->toHaveCount(1)
        ->and($reasons[0])->toContain($needle)
        ->and($reasons[0])->not->toContain($graph->email);
})->with([
    'payment held' => ['status', 'payment_held', 'payment state'],
    'payment released' => ['status', 'payment_released', 'payment state'],
    'a payment id' => ['payment_id', '99', 'payment id'],
]);

it('does not refuse a subject for another creator\'s payment', function (): void {
    $a = ErasureGraph::seedSubject('a');
    $b = ErasureGraph::seedSubject('b');
    DB::table('campaign_assignments')->where('creator_id', $b->creator->id)->update(['status' => 'payment_held', 'payment_id' => 7]);

    expect(Refusals::for(SubjectSet::resolve($a->user)))->toBe([]);
});

it('makes the engine itself refuse, before it writes anything', function (): void {
    $graph = ErasureGraph::seedSubject('a');
    $subject = SubjectSet::resolve($graph->user);
    DB::table('campaign_assignments')->where('creator_id', $graph->creator->id)->update(['status' => 'payment_held']);
    $before = engineGuardDigests();

    $run = fn () => (new ErasureEngine)->run($subject);

    expect($run)->toThrow(ErasureRefused::class, 'payment state')
        ->and(engineGuardDigests())->toBe($before);
});

it('makes the engine refuse a real vendor too', function (): void {
    $graph = ErasureGraph::seedSubject('a');
    DB::table('creator_payout_methods')->where('creator_id', $graph->creator->id)->update(['provider' => 'stripe']);
    $subject = SubjectSet::resolve($graph->user);

    expect(fn () => (new ErasureEngine)->run($subject))->toThrow(ErasureRefused::class, 'stripe');
});

/** @return array<string, string> */
function engineGuardDigests(): array
{
    $out = [];
    foreach (paymentSchemaTables() as $name) {
        $rows = DB::table($name)->get()->map(fn ($r): string => json_encode($r, JSON_THROW_ON_ERROR))->sort()->values()->all();
        $out[$name] = md5(json_encode($rows, JSON_THROW_ON_ERROR));
    }

    return $out;
}
