<?php

declare(strict_types=1);

use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Models\Creator;
use App\Modules\Erasure\Enums\ErasureLedgerState;
use App\Modules\Erasure\Models\ErasureLedger;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// The grace boundary is a comparison against `now()`: a clock that moves during a spec (a loaded
// machine, a second boundary) turns "one second ahead" into "due". Found by the full run at the C2 gate.
beforeEach(fn () => $this->freezeTime());

/*
|--------------------------------------------------------------------------
| AH-104c C2-2 — the admin erasure queue API
|--------------------------------------------------------------------------
|
|   GET /api/v1/admin/compliance/erasure-queue?tab=&cursor=&per_page=
|
| Six tabs, derived by precedence erased > in progress > held > blocked > ready > pending. The
| Erased tab is the ledger only. Blockers are codes, never sentences.
*/

const QUEUE_URL = '/api/v1/admin/compliance/erasure-queue';

function queueAdmin(): User
{
    return User::factory()->platformAdmin()->createOne(['two_factor_confirmed_at' => now()]);
}

/**
 * A creator in one queue state.
 *
 * @param  array<string, mixed>  $user
 */
function queueCreator(string $state, array $user = []): User
{
    $attributes = match ($state) {
        'pending' => ['deletion_requested_at' => now()->subDay(), 'deletion_scheduled_for' => now()->addDays(13)],
        'due' => ['deletion_requested_at' => now()->subDays(15), 'deletion_scheduled_for' => now()->subDay()],
        'held_due' => ['deletion_requested_at' => now()->subDays(15), 'deletion_scheduled_for' => now()->subDay(), 'erasure_held_at' => now()],
        'held_pending' => ['deletion_requested_at' => now()->subDay(), 'deletion_scheduled_for' => now()->addDays(13), 'erasure_held_at' => now()],
        'held_never_asked' => ['erasure_held_at' => now()],
        'claimed' => ['deletion_requested_at' => now()->subDays(15), 'deletion_scheduled_for' => now()->subDay(), 'erasure_started_at' => now()],
        'untouched' => [],
        default => throw new InvalidArgumentException($state),
    };

    $account = User::factory()->creator()->createOne($user + $attributes);
    Creator::factory()->createOne(['user_id' => $account->id]);

    return $account->refresh();
}

function queuePayment(User $user): void
{
    CampaignAssignment::factory()->createOne([
        'creator_id' => Creator::query()->where('user_id', $user->id)->firstOrFail()->id,
        'status' => AssignmentStatus::PaymentHeld,
    ]);
}

/** @return list<string> the user ulids of a tab, first page of 100 */
function queueTab(string $tab, ?User $admin = null): array
{
    $response = actingAs($admin ?? queueAdmin(), 'web_admin')->getJson(QUEUE_URL.'?tab='.$tab.'&per_page=100');
    expect($response->status())->toBe(200);

    return array_column($response->json('data'), 'id');
}

// ── Access ──────────────────────────────────────────────────────────────────

it('401s without a session and 403s a non-admin', function (): void {
    expect($this->getJson(QUEUE_URL)->status())->toBe(401);

    $agency = User::factory()->createOne(['type' => UserType::AgencyUser, 'two_factor_confirmed_at' => now()]);
    expect($this->actingAs($agency, 'web_admin')->getJson(QUEUE_URL)->status())->toBe(403);
});

it('422s an unknown tab, a bad cursor and a bad page size', function (string $query): void {
    expect($this->actingAs(queueAdmin(), 'web_admin')->getJson(QUEUE_URL.'?'.$query)->status())->toBe(422);
})->with(['tab=everything', 'cursor=not%20a%20cursor!', 'per_page=0', 'per_page=101']);

it('opens on the Pending tab', function (): void {
    $pending = queueCreator('pending');
    queueCreator('held_never_asked');

    $response = $this->actingAs(queueAdmin(), 'web_admin')->getJson(QUEUE_URL);

    expect($response->json('meta.tab'))->toBe('pending')
        ->and(array_column($response->json('data'), 'id'))->toBe([$pending->ulid]);
});

// ── Each tab returns exactly its state ──────────────────────────────────────

it('puts each account in exactly one tab, by the precedence', function (): void {
    $pending = queueCreator('pending');
    $ready = queueCreator('due');
    $blocked = queueCreator('due');
    queuePayment($blocked);
    $heldDue = queueCreator('held_due');
    $heldPending = queueCreator('held_pending');
    $heldNever = queueCreator('held_never_asked');
    $claimed = queueCreator('claimed');
    $claimedAndHeld = queueCreator('claimed', ['erasure_held_at' => now()]);
    queueCreator('untouched');
    $erasedLedger = ErasureLedger::factory()->completed()->createOne();

    $tabs = [
        'pending' => [$pending->ulid],
        'ready' => [$ready->ulid],
        'blocked' => [$blocked->ulid],
        'held' => [$heldDue->ulid, $heldPending->ulid, $heldNever->ulid],
        'in_progress' => [$claimed->ulid, $claimedAndHeld->ulid],
        'erased' => [$erasedLedger->user_ulid],
    ];

    foreach ($tabs as $tab => $expected) {
        $actual = queueTab($tab);
        sort($actual);
        sort($expected);
        expect($actual)->toBe($expected, "tab {$tab}");
    }
});

it('shows a claimed account as In progress even when it also carries a hold', function (): void {
    $claimedAndHeld = queueCreator('claimed', ['erasure_held_at' => now()]);

    $row = $this->actingAs(queueAdmin(), 'web_admin')->getJson(QUEUE_URL.'?tab=in_progress')->json('data.0');

    expect($row['id'])->toBe($claimedAndHeld->ulid)
        ->and($row['attributes']['state'])->toBe('in_progress')
        ->and($row['attributes']['held'])->toBeTrue()
        ->and(queueTab('held'))->toBe([]);
});

it('files a due creator under Ready (not Pending), and a held due creator under Held (not Ready)', function (): void {
    $due = queueCreator('due');
    $heldDue = queueCreator('held_due');

    expect(queueTab('pending'))->toBe([])
        ->and(queueTab('ready'))->toBe([$due->ulid])
        ->and(queueTab('held'))->toBe([$heldDue->ulid]);
});

it('treats the grace boundary exactly: due at the instant it ends, pending one second before', function (): void {
    $before = queueCreator('pending', ['deletion_scheduled_for' => now()->addSecond()]);
    $at = queueCreator('pending', ['deletion_scheduled_for' => now()]);

    expect(queueTab('pending'))->toBe([$before->ulid])
        ->and(queueTab('ready'))->toBe([$at->ulid]);
});

it('keeps the blocker codes on the Blocked rows, and only the codes', function (): void {
    $blocked = queueCreator('due');
    queuePayment($blocked);

    $row = $this->actingAs(queueAdmin(), 'web_admin')->getJson(QUEUE_URL.'?tab=blocked')->json('data.0');

    expect($row['attributes']['blockers'])->toBe(['payment'])
        ->and($row['attributes']['state'])->toBe('blocked')
        ->and(json_encode($row))->not->toContain('payment_held');
});

it('puts a creator with no profile under Blocked with the not-erasable code, not a 500', function (): void {
    $account = User::factory()->creator()->createOne(['deletion_requested_at' => now()->subDays(15), 'deletion_scheduled_for' => now()->subDay()]);

    $row = $this->actingAs(queueAdmin(), 'web_admin')->getJson(QUEUE_URL.'?tab=blocked')->json('data.0');

    expect($row['id'])->toBe($account->ulid)
        ->and($row['attributes']['blockers'])->toBe(['not_erasable']);
});

it('says how far a claimed account got, from the ledger', function (): void {
    $claimed = queueCreator('claimed');
    ErasureLedger::factory()->createOne(['user_ulid' => $claimed->ulid, 'state' => ErasureLedgerState::Incomplete]);
    $fresh = queueCreator('claimed');

    $rows = $this->actingAs(queueAdmin(), 'web_admin')->getJson(QUEUE_URL.'?tab=in_progress')->collect('data')->keyBy('id');

    expect($rows[$claimed->ulid]['attributes']['ledger_state'])->toBe('incomplete')
        ->and($rows[$fresh->ulid]['attributes']['ledger_state'])->toBe('claimed');
});

it('leaves erased, agency and admin users out of the live tabs', function (): void {
    $erased = queueCreator('due', ['erased_at' => now()]);
    $agency = User::factory()->createOne(['type' => UserType::AgencyUser, 'deletion_requested_at' => now()->subDays(15), 'deletion_scheduled_for' => now()->subDay()]);

    foreach (['pending', 'ready', 'blocked', 'held', 'in_progress'] as $tab) {
        expect(queueTab($tab))->not->toContain($erased->ulid);
        expect(queueTab($tab))->not->toContain($agency->ulid);
    }
});

// ── The Erased tab: the ledger only ─────────────────────────────────────────

it('lists the Erased tab from the ledger alone, with a pinned key set and none of the person', function (): void {
    $person = User::factory()->creator()->createOne([
        'name' => 'Zed Quillfeather',
        'email' => 'zed.quillfeather@example.test',
        'erased_at' => now(),
    ]);
    ErasureLedger::factory()->completed()->createOne(['user_ulid' => $person->ulid]);
    // A ledger row whose user row is gone altogether is still listed: nothing here reads `users`.
    $orphan = ErasureLedger::factory()->completed()->createOne();
    ErasureLedger::factory()->createOne(['state' => ErasureLedgerState::Incomplete]);

    $response = $this->actingAs(queueAdmin(), 'web_admin')->getJson(QUEUE_URL.'?tab=erased');

    expect(array_column($response->json('data'), 'id'))->toContain($person->ulid)->toContain($orphan->user_ulid)->toHaveCount(2);

    foreach ($response->json('data') as $row) {
        expect(array_keys($row))->toBe(['id', 'type', 'attributes'])
            ->and(array_keys($row['attributes']))->toBe(['state', 'ledger_state', 'erased_at']);
    }

    $body = $response->getContent();
    foreach (['Zed', 'Quillfeather', 'example.test'] as $needle) {
        expect($body)->not->toContain($needle);
    }
});

// ── Counts ──────────────────────────────────────────────────────────────────

it('counts every tab, from the same derivation as the tabs', function (): void {
    queueCreator('pending');
    queueCreator('pending');
    queueCreator('due');
    $blocked = queueCreator('due');
    queuePayment($blocked);
    queueCreator('held_never_asked');
    queueCreator('claimed');
    ErasureLedger::factory()->completed()->count(3)->create();
    ErasureLedger::factory()->createOne(['state' => ErasureLedgerState::Incomplete]); // a stopped run is not an erasure

    $counts = $this->actingAs(queueAdmin(), 'web_admin')->getJson(QUEUE_URL)->json('meta.counts');

    expect($counts)->toBe([
        'pending' => 2, 'ready' => 1, 'blocked' => 1, 'held' => 1, 'in_progress' => 1, 'erased' => 3, 'due_scan_capped' => false,
    ]);
});

// ── Pagination ──────────────────────────────────────────────────────────────

it('pages every tab stably, in order, with no repeats and no gaps', function (string $tab): void {
    $admin = queueAdmin();
    match ($tab) {
        'pending' => collect(range(1, 5))->each(fn () => queueCreator('pending')),
        'ready' => collect(range(1, 5))->each(fn () => queueCreator('due')),
        'blocked' => collect(range(1, 5))->each(fn () => queuePayment(queueCreator('due'))),
        'held' => collect(range(1, 5))->each(fn () => queueCreator('held_never_asked')),
        'in_progress' => collect(range(1, 5))->each(fn () => queueCreator('claimed')),
        'erased' => ErasureLedger::factory()->completed()->count(5)->create(),
        default => throw new InvalidArgumentException($tab),
    };
    $all = queueTab($tab, $admin);
    expect($all)->toHaveCount(5);

    $seen = [];
    $cursor = null;
    $pages = 0;
    do {
        $response = $this->actingAs($admin, 'web_admin')->getJson(QUEUE_URL.'?tab='.$tab.'&per_page=2'.($cursor !== null ? '&cursor='.$cursor : ''));
        expect($response->status())->toBe(200);
        array_push($seen, ...array_column($response->json('data'), 'id'));
        $cursor = $response->json('meta.next_cursor');
        $pages++;
    } while ($cursor !== null && $pages < 10);

    expect($pages)->toBe(3)->and($seen)->toBe($all);
})->with(['pending', 'ready', 'blocked', 'held', 'in_progress', 'erased']);

it('does not repeat or skip a row when one is added ahead of the cursor between pages', function (): void {
    $admin = queueAdmin();
    foreach (range(1, 4) as $_) {
        queueCreator('pending');
    }
    $first = $this->actingAs($admin, 'web_admin')->getJson(QUEUE_URL.'?tab=pending&per_page=2');
    queueCreator('pending');
    $second = $this->actingAs($admin, 'web_admin')->getJson(QUEUE_URL.'?tab=pending&per_page=2&cursor='.$first->json('meta.next_cursor'));

    $ids = array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'));
    expect($ids)->toHaveCount(4)->and(array_unique($ids))->toHaveCount(4);
});

// ── Query count ─────────────────────────────────────────────────────────────

/** Queries for the first page of a tab, once the session and admin are warm. */
function queueQueries(string $tab, User $admin): int
{
    // One warm-up request: the first one of a session also loads the signed-in admin.
    actingAs($admin, 'web_admin')->getJson(QUEUE_URL.'?tab='.$tab.'&per_page=25')->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    actingAs($admin, 'web_admin')->getJson(QUEUE_URL.'?tab='.$tab.'&per_page=25')->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('runs the same number of queries for a page of 25 as for a page of 2, on the SQL-only tabs', function (string $tab, string $state): void {
    $admin = queueAdmin();
    foreach (range(1, 2) as $_) {
        queueCreator($state, ['erasure_held_by_user_id' => $admin->id]);
    }
    $small = queueQueries($tab, $admin);

    foreach (range(1, 23) as $_) {
        queueCreator($state, ['erasure_held_by_user_id' => $admin->id]);
    }
    $large = queueQueries($tab, $admin);

    expect($large)->toBe($small)->and($large)->toBeLessThan(25);
})->with([
    'pending' => ['pending', 'pending'],
    'held' => ['held', 'held_pending'],
    'in progress' => ['in_progress', 'claimed'],
]);

it('caps the due scan, and says so', function (): void {
    $admin = queueAdmin();
    $now = now();
    // Bulk rows (no creator profile: each is evaluated as not-erasable, which is cheap and enough to hit the cap).
    $rows = [];
    foreach (range(1, 205) as $i) {
        $rows[] = [
            'ulid' => (string) Str::ulid(), 'name' => "Due {$i}", 'email' => "due{$i}@example.test", 'password' => 'x',
            'type' => UserType::Creator->value, 'deletion_requested_at' => $now->copy()->subDays(15), 'deletion_scheduled_for' => $now->copy()->subDay(),
            'created_at' => $now, 'updated_at' => $now,
        ];
    }
    DB::table('users')->insert($rows);

    $response = $this->actingAs($admin, 'web_admin')->getJson(QUEUE_URL.'?tab=blocked&per_page=100');

    expect($response->json('meta.counts.due_scan_capped'))->toBeTrue()
        ->and($response->json('meta.counts.blocked'))->toBe(200);
});

// ── The row ─────────────────────────────────────────────────────────────────

it('shapes a live row: the account, the dates, the hold, and no guard sentences', function (): void {
    $admin = queueAdmin();
    $held = queueCreator('held_pending', ['erasure_held_by_user_id' => $admin->id, 'name' => 'Held Person']);

    $row = $this->actingAs($admin, 'web_admin')->getJson(QUEUE_URL.'?tab=held')->json('data.0');

    expect(array_keys($row['attributes']))->toBe([
        'state', 'creator_ulid', 'name', 'email', 'requested_at', 'due_at', 'days_left',
        'held', 'held_at', 'held_by', 'claimed_at', 'ledger_state', 'blockers',
    ])
        ->and($row['id'])->toBe($held->ulid)
        ->and($row['attributes']['name'])->toBe('Held Person')
        ->and($row['attributes']['held_by'])->toBe($admin->name)
        ->and($row['attributes']['days_left'])->toBe(13);
});
