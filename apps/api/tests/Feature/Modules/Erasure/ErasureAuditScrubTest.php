<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Creators\Models\CreatorPayoutMethod;
use App\Modules\Erasure\Enums\ErasureLedgerState;
use App\Modules\Erasure\Execution\AuditScrub;
use App\Modules\Erasure\Execution\AuditScrubViolation;
use App\Modules\Erasure\Execution\DatabaseAuditScrub;
use App\Modules\Erasure\Execution\ErasureEngine;
use App\Modules\Erasure\Files\ObjectStore;
use App\Modules\Erasure\Models\ErasureLedger;
use App\Modules\Erasure\Run\ErasureRun;
use App\Modules\Erasure\Selection\AuditRowScrub;
use App\Modules\Erasure\Selection\DryRun;
use App\Modules\Erasure\Selection\Selections;
use App\Modules\Erasure\Subject\SubjectSet;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ErasureGraph;
use Tests\Support\ErasureSnapshot;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Stage 4e against the REAL scrub (the run specs use a fake). Rows are inserted
 * raw so each predicate is exercised with exactly the columns the map names.
 */
function erasureAuditFixture(): array
{
    Storage::fake('media');
    $a = ErasureGraph::seedSubject('a');
    $b = ErasureGraph::seedSubject('b');
    DB::table('audit_logs')->delete();

    return [$a, $b, SubjectSet::resolve($a->user)];
}

/** @param array<string, mixed> $o */
function erasureAuditRow(array $o): int
{
    foreach (['metadata', 'before', 'after'] as $column) {
        if (isset($o[$column])) {
            $o[$column] = json_encode($o[$column], JSON_THROW_ON_ERROR);
        }
    }

    return (int) DB::table('audit_logs')->insertGetId($o + [
        'ulid' => (string) Str::ulid(),
        'actor_type' => 'system',
        'action' => AuditAction::AuthLoginFailed->value,
        'ip' => '203.0.113.7',
        'user_agent' => 'Mozilla/5.0 (test)',
        'created_at' => now()->toDateTimeString(),
    ]);
}

function erasureQueryPlan(string $selectionId, SubjectSet $subject): string
{
    foreach (Selections::all() as $selection) {
        if ($selection->id === $selectionId) {
            $builder = $selection->builder($subject, DB::connection());

            return collect(DB::select('EXPLAIN QUERY PLAN '.$builder->toSql(), $builder->getBindings()))->pluck('detail')->implode(' | ');
        }
    }

    throw new LogicException("No selection {$selectionId}.");
}

/** @return array<string, mixed> */
function erasureAuditRead(int $id): array
{
    $row = (array) DB::table('audit_logs')->find($id);
    foreach (['metadata', 'before', 'after'] as $column) {
        $row[$column] = $row[$column] === null ? null : json_decode((string) $row[$column], true);
    }

    return $row;
}

it('S1: the subject\'s own actions lose ip, user agent and whatever address was typed (whoever\'s), and keep everything else', function (): void {
    [$a, , $subject] = erasureAuditFixture();
    $id = erasureAuditRow(['actor_type' => 'user', 'actor_id' => $a->user->id, 'actor_role' => 'creator', 'action' => AuditAction::AuthLoginSucceeded->value, 'metadata' => ['email' => 'typed-by-someone-else@example.test', 'reason' => 'ok'], 'reason' => 'kept reason']);

    (new DatabaseAuditScrub)->run($subject);
    $row = erasureAuditRead($id);

    expect($row['ip'])->toBeNull()
        ->and($row['user_agent'])->toBeNull()
        ->and($row['metadata'])->toBe(['reason' => 'ok'])
        ->and($row['reason'])->toBe('kept reason')
        ->and($row['actor_id'])->toBe($a->user->id)
        ->and($row['actor_role'])->toBe('creator');
});

it('writes an emptied snapshot back as an empty object, not a list', function (): void {
    [$a, , $subject] = erasureAuditFixture();
    $id = erasureAuditRow(['actor_type' => 'user', 'actor_id' => $a->user->id, 'metadata' => ['email' => $a->email]]);

    (new DatabaseAuditScrub)->run($subject);

    expect(DB::table('audit_logs')->where('id', $id)->value('metadata'))->toBe('{}');
});

it('S2: a snapshot of the user loses the person\'s columns, and keeps the rest and the actor\'s ip', function (): void {
    [$a, , $subject] = erasureAuditFixture();
    $id = erasureAuditRow([
        'actor_type' => 'user', 'actor_id' => $a->agencyMember->id, 'subject_type' => $a->user->getMorphClass(), 'subject_id' => $a->user->id,
        'action' => AuditAction::UserSuspended->value, 'reason' => 'spam',
        'before' => ['email' => $a->email, 'name' => 'Subject', 'last_name' => 'A', 'last_login_ip' => '198.51.100.1', 'suspended_reason' => null, 'is_suspended' => false],
        'after' => ['email' => $a->email, 'name' => 'Subject', 'last_name' => 'A', 'last_login_ip' => '198.51.100.1', 'suspended_reason' => 'free text', 'is_suspended' => true],
    ]);

    (new DatabaseAuditScrub)->run($subject);
    $row = erasureAuditRead($id);

    expect($row['before'])->toBe(['is_suspended' => false])
        ->and($row['after'])->toBe(['is_suspended' => true])
        ->and($row['reason'])->toBe('spam')
        ->and($row['ip'])->toBe('203.0.113.7')
        ->and($row['user_agent'])->toBe('Mozilla/5.0 (test)');
});

it('S3: the creator snapshot loses display_name; an admin edit loses its old and new value and keeps the field name', function (): void {
    [$a, , $subject] = erasureAuditFixture();
    $snapshot = erasureAuditRow(['actor_type' => 'user', 'actor_id' => $a->agencyMember->id, 'subject_type' => $a->creator->getMorphClass(), 'subject_id' => $a->creator->id, 'action' => AuditAction::CreatorUpdated->value, 'after' => ['display_name' => 'Subject A', 'tier' => 'gold']]);
    $edit = erasureAuditRow(['actor_type' => 'user', 'actor_id' => $a->agencyMember->id, 'subject_type' => $a->creator->getMorphClass(), 'subject_id' => $a->creator->id, 'action' => AuditAction::CreatorAdminFieldUpdated->value, 'reason' => 'typo', 'metadata' => ['field' => 'bio', 'before' => 'old text', 'after' => 'new text']]);
    $approved = erasureAuditRow(['actor_type' => 'user', 'actor_id' => $a->agencyMember->id, 'subject_type' => $a->creator->getMorphClass(), 'subject_id' => $a->creator->id, 'action' => AuditAction::CreatorApproved->value, 'metadata' => ['welcome_message' => 'Hi Subject!', 'tier' => 'gold']]);
    $kyc = erasureAuditRow(['actor_type' => 'user', 'actor_id' => $a->agencyMember->id, 'subject_type' => $a->creator->getMorphClass(), 'subject_id' => $a->creator->id, 'action' => AuditAction::CreatorKycManuallyVerified->value, 'metadata' => ['note' => 'checked passport in person']]);

    (new DatabaseAuditScrub)->run($subject);

    expect(erasureAuditRead($snapshot)['after'])->toBe(['tier' => 'gold'])
        ->and(erasureAuditRead($edit)['metadata'])->toBe(['field' => 'bio'])
        ->and(erasureAuditRead($edit)['reason'])->toBe('typo')
        ->and(erasureAuditRead($approved)['metadata'])->toBe(['tier' => 'gold'])
        ->and(erasureAuditRead($kyc)['metadata'])->toBe(['note' => 'checked passport in person'])
        ->and(erasureAuditRead($snapshot)['ip'])->toBe('203.0.113.7');
});

it('S4: a payout method snapshot loses the vendor account key', function (): void {
    [$a, , $subject] = erasureAuditFixture();
    $methodId = $subject->payoutMethodIds[0];
    $id = erasureAuditRow(['actor_type' => 'user', 'actor_id' => $a->user->id, 'subject_type' => (new CreatorPayoutMethod)->getMorphClass(), 'subject_id' => $methodId, 'action' => AuditAction::CreatorPayoutMethodCreated->value, 'after' => ['provider_account_id' => 'acct_123', 'provider' => 'mock']]);

    (new DatabaseAuditScrub)->run($subject);

    expect(erasureAuditRead($id)['after'])->toBe(['provider' => 'mock']);
});

it('S5: any actor, the address in any case; ip goes unless another user acted', function (): void {
    [$a, , $subject] = erasureAuditFixture();
    $anonymous = erasureAuditRow(['metadata' => ['email' => strtoupper($a->email), 'reason' => 'unknown_email']]);
    $byAgency = erasureAuditRow(['actor_type' => 'user', 'actor_id' => $a->agencyMember->id, 'action' => AuditAction::InvitationCreated->value, 'metadata' => ['email' => $a->email, 'role' => 'x']]);

    (new DatabaseAuditScrub)->run($subject);

    expect(erasureAuditRead($anonymous)['metadata'])->toBe(['reason' => 'unknown_email'])
        ->and(erasureAuditRead($anonymous)['ip'])->toBeNull()
        ->and(erasureAuditRead($anonymous)['user_agent'])->toBeNull()
        ->and(erasureAuditRead($byAgency)['metadata'])->toBe(['role' => 'x'])
        ->and(erasureAuditRead($byAgency)['ip'])->toBe('203.0.113.7');
});

it('changes only the five columns, on every row, and leaves a bystander\'s rows byte-identical', function (): void {
    Storage::fake('media');
    $a = ErasureGraph::seedSubject('a');
    $b = ErasureGraph::seedSubject('b');
    $subject = SubjectSet::resolve($a->user);
    $before = DB::table('audit_logs')->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all();
    $bystander = array_filter($before, fn (array $r): bool => ($r['actor_id'] ?? null) === $b->user->id || ($r['subject_id'] ?? null) === $b->user->id || ($r['subject_id'] ?? null) === $b->creator->id);

    $counts = (new DatabaseAuditScrub)->run($subject);
    $after = DB::table('audit_logs')->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all();

    expect($counts['audit_logs.union'])->toBeGreaterThan(0)->and(count($after))->toBe(count($before));
    $strip = fn (array $r): array => array_diff_key($r, array_flip(AuditRowScrub::COLUMNS));
    foreach ($before as $i => $row) {
        expect($strip($after[$i]))->toBe($strip($row));
    }
    foreach ($bystander as $i => $row) {
        expect($after[$i])->toBe($row);
    }
    expect($bystander)->not->toBe([]);
});

it('decides row by row too: a bystander\'s rows are no change for this subject, whatever the query returned', function (): void {
    [$a, $b, $subject] = erasureAuditFixture();
    $rows = [
        ['actor_type' => 'user', 'actor_id' => $b->user->id, 'metadata' => ['email' => $b->email]],
        ['subject_type' => $b->user->getMorphClass(), 'subject_id' => $b->user->id, 'before' => ['email' => $b->email, 'name' => 'B']],
        ['subject_type' => $b->creator->getMorphClass(), 'subject_id' => $b->creator->id, 'after' => ['display_name' => 'B']],
        ['metadata' => ['email' => $b->email]],
    ];

    foreach ($rows as $shape) {
        $row = new stdClass;
        foreach ((array) DB::table('audit_logs')->find(erasureAuditRow($shape)) as $column => $value) {
            $row->{$column} = $value;
        }
        expect(AuditRowScrub::changes($row, $subject))->toBe([]);
    }

    expect($a->user->id)->not->toBe($b->user->id);
});

it('leaves nothing of the person in the five columns once it has run', function (): void {
    Storage::fake('media');
    $a = ErasureGraph::seedSubject('a');
    $subject = SubjectSet::resolve($a->user);

    (new DatabaseAuditScrub)->run($subject);

    $leaks = [];
    foreach (DB::table('audit_logs')->get() as $row) {
        $blob = strtolower(json_encode([$row->metadata, $row->before, $row->after], JSON_THROW_ON_ERROR));
        if (str_contains($blob, strtolower($a->email))) {
            $leaks[] = $row->action;
        }
    }

    expect($leaks)->toBe([]);
});

it('counts in the dry-run exactly what it then changes, per predicate, and a second run changes nothing', function (): void {
    Storage::fake('media');
    $a = ErasureGraph::seedSubject('a');
    ErasureGraph::seedSubject('b');
    $subject = SubjectSet::resolve($a->user);
    $planned = array_filter((new DryRun)->run($subject)->counts(), fn (string $id): bool => str_starts_with($id, 'audit_logs.'), ARRAY_FILTER_USE_KEY);
    ksort($planned);

    $first = (new DatabaseAuditScrub)->run($subject);
    ksort($first);
    $afterFirst = ErasureSnapshot::tables();
    $second = (new DatabaseAuditScrub)->run($subject);

    expect($first)->toBe($planned)
        ->and($planned['audit_logs.union'])->toBeGreaterThan(0)
        ->and(array_sum($second))->toBe(0)
        ->and(ErasureSnapshot::tables())->toBe($afterFirst)
        ->and(array_sum(array_filter((new DryRun)->run($subject)->counts(), fn (string $id): bool => str_starts_with($id, 'audit_logs.'), ARRAY_FILTER_USE_KEY)))->toBe(0);
});

it('refuses, before any statement, to write a column outside the five', function (): void {
    [$a, , $subject] = erasureAuditFixture();
    $id = erasureAuditRow(['actor_type' => 'user', 'actor_id' => $a->user->id, 'reason' => 'kept']);
    $before = erasureAuditRead($id);
    $write = new ReflectionMethod(DatabaseAuditScrub::class, 'write');

    foreach (['reason', 'action', 'actor_id', 'created_at', 'ulid'] as $column) {
        expect(fn () => $write->invoke(new DatabaseAuditScrub, DB::connection(), $id, [$column => 'x']))->toThrow(AuditScrubViolation::class);
    }

    expect(erasureAuditRead($id))->toBe($before)
        ->and(AuditRowScrub::COLUMNS)->toBe(['ip', 'user_agent', 'metadata', 'before', 'after']);
});

it('never produces a change outside the five columns, whatever the row looks like', function (): void {
    [$a, , $subject] = erasureAuditFixture();
    $shapes = [
        ['actor_type' => 'user', 'actor_id' => $a->user->id, 'metadata' => ['email' => $a->email]],
        ['subject_type' => $a->user->getMorphClass(), 'subject_id' => $a->user->id, 'before' => ['email' => 'x', 'name' => 'y']],
        ['subject_type' => $a->creator->getMorphClass(), 'subject_id' => $a->creator->id, 'action' => AuditAction::CreatorAdminFieldUpdated->value, 'metadata' => ['before' => 1, 'after' => 2]],
        ['metadata' => ['email' => $a->email]],
        ['actor_type' => 'user', 'actor_id' => $a->user->id, 'subject_type' => $a->user->getMorphClass(), 'subject_id' => $a->user->id, 'metadata' => ['email' => $a->email], 'before' => ['email' => 'x'], 'after' => ['name' => 'y']],
    ];

    foreach ($shapes as $shape) {
        $row = new stdClass;
        foreach ((array) DB::table('audit_logs')->find(erasureAuditRow($shape)) as $column => $value) {
            $row->{$column} = $value;
        }
        expect(array_diff(array_keys(AuditRowScrub::changes($row, $subject)), AuditRowScrub::COLUMNS))->toBe([]);
    }
});

it('is the only code under app/ that updates or deletes audit_logs', function (): void {
    $offenders = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        $write = '/(table\(\s*[\'"]audit_logs[\'"]\s*\)|AuditLog::(query|withoutGlobalScopes|on)\(\)|\$auditLog\b)[^;]*->(update|delete|forceDelete|truncate|increment|decrement|upsert|updateOrInsert)\(/s';
        $raw = '/\b(UPDATE|DELETE\s+FROM|TRUNCATE(\s+TABLE)?)\s+"?audit_logs\b/i';
        if (preg_match($write, $source) === 1 || preg_match($raw, $source) === 1) {
            $offenders[] = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    expect($offenders)->toBe(['Modules'.DIRECTORY_SEPARATOR.'Erasure'.DIRECTORY_SEPARATOR.'Execution'.DIRECTORY_SEPARATOR.'DatabaseAuditScrub.php']);
});

it('scrubs a row written after the first pass, inside the same transaction (a login during the file step)', function (): void {
    Storage::fake('media');
    $a = ErasureGraph::seedSubject('a');
    $a->putFiles();
    $late = null;
    // The file step is where time passes; a request that lands during it writes an audit row the first pass never saw.
    app()->instance(ObjectStore::class, new class($a, $late) implements ObjectStore
    {
        public function __construct(private readonly ErasureGraph $graph, private ?int &$late) {}

        public function list(string $prefix): array
        {
            return array_values(array_map(strval(...), Storage::disk('media')->allFiles($prefix)));
        }

        public function delete(string $key): void
        {
            Storage::disk('media')->delete($key);
            $this->late ??= erasureAuditRow(['actor_type' => 'user', 'actor_id' => $this->graph->user->id, 'action' => AuditAction::AuthLoginSucceeded->value]);
        }
    });
    $run = app(ErasureRun::class);

    $run->execute($a->user, $run->plan($a->user)->digest(), $a->user->ulid);

    $counts = ErasureLedger::query()->sole()->counts;

    // The closing pass is counted in the ledger: the late row is one more S1 row than the dry-run saw.
    expect($late)->not->toBeNull()
        ->and(data_get($counts, ['audit_scrub', 'audit_logs.S1']))->toBe(ErasureGraph::expectedCounts()['audit_logs.S1'] + 1)
        ->and(erasureAuditRead((int) $late)['ip'])->toBeNull()
        ->and(erasureAuditRead((int) $late)['user_agent'])->toBeNull();
});

it('rolls the whole erasure back when the closing pass fails: the account is intact', function (): void {
    Storage::fake('media');
    $a = ErasureGraph::seedSubject('a');
    $a->putFiles();
    $real = new DatabaseAuditScrub;
    app()->instance(AuditScrub::class, new class($real) implements AuditScrub
    {
        private int $calls = 0;

        public function __construct(private readonly AuditScrub $inner) {}

        public function available(): bool
        {
            return true;
        }

        public function run(SubjectSet $subject): array
        {
            if (++$this->calls === 2) {
                throw new RuntimeException('the closing pass failed');
            }

            return $this->inner->run($subject);
        }
    });
    $run = app(ErasureRun::class);
    $digest = $run->plan($a->user)->digest();

    expect(fn () => $run->execute($a->user, $digest, $a->user->ulid))->toThrow(RuntimeException::class)
        ->and(User::withTrashed()->findOrFail($a->user->id)->isErased())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $a->user->id)->count())->toBe(2)
        ->and(ErasureLedger::query()->sole()->state)->toBe(ErasureLedgerState::Incomplete);
});

it('reads in batches: the notification scrub and the audit scrub never issue an unbounded read', function (): void {
    Storage::fake('media');
    $a = ErasureGraph::seedSubject('a');
    $other = User::factory()->create();
    $rows = [];
    for ($i = 0; $i < 1100; $i++) {
        $rows[] = ['ulid' => (string) Str::ulid(), 'recipient_user_id' => $other->id, 'actor_user_id' => $a->user->id, 'type' => 'message.received', 'data' => json_encode(['sender_name' => 'Subject A', 'n' => $i], JSON_THROW_ON_ERROR), 'created_at' => now()->toDateTimeString()];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('notifications')->insert($chunk);
    }
    for ($i = 0; $i < 1100; $i++) {
        erasureAuditRow(['actor_type' => 'user', 'actor_id' => $a->user->id, 'action' => AuditAction::AuthLoginSucceeded->value]);
    }
    $subject = SubjectSet::resolve($a->user);
    $selects = [];
    DB::listen(function ($q) use (&$selects): void {
        if (preg_match('/^\s*select \* from "(notifications|audit_logs)"/i', $q->sql, $m) === 1) {
            $selects[] = [$m[1], $q->sql];
        }
    });

    $engine = new ErasureEngine;
    $engine->run($subject);
    (new DatabaseAuditScrub)->run($subject);

    $unbounded = array_values(array_filter($selects, fn (array $s): bool => ! str_contains(strtolower($s[1]), ' limit ')));
    $batches = fn (string $table): int => count(array_filter($selects, fn (array $s): bool => $s[0] === $table && str_contains(strtolower($s[1]), ' limit ')));

    expect(DB::table('notifications')->where('actor_user_id', $a->user->id)->whereRaw("data LIKE '%sender_name%'")->count())->toBe(0)
        ->and($batches('notifications'))->toBeGreaterThanOrEqual(3)
        ->and($batches('audit_logs'))->toBeGreaterThanOrEqual(2)
        ->and(array_values(array_filter($unbounded, fn (array $s): bool => str_contains($s[1], 'where "actor_user_id"') || str_contains($s[1], '"actor_id"'))))->toBe([]);
});

it('has an index behind every lookup the notification and audit scrubs make by actor or subject', function (): void {
    Storage::fake('media');
    $a = ErasureGraph::seedSubject('a');
    $subject = SubjectSet::resolve($a->user);
    $plan = fn (string $id): string => erasureQueryPlan($id, $subject);

    expect($plan('notifications.actor'))->toContain('idx_notifications_actor')
        ->and($plan('notifications.subject'))->toContain('idx_notifications_subject')
        ->and($plan('audit_logs.S1'))->toContain('idx_audit_actor')
        ->and($plan('audit_logs.S2'))->toContain('idx_audit_subject')
        ->and($plan('audit_logs.S3'))->toContain('idx_audit_subject');
});

it('puts the cost in the dry-run line, and keeps it out of the digest', function (): void {
    Storage::fake('media');
    $a = ErasureGraph::seedSubject('a');
    $run = app(ErasureRun::class);
    $before = $run->plan($a->user);
    $other = User::factory()->create();
    // A notification about the creator that carries nothing to scrub: one more row to READ, none to change.
    DB::table('notifications')->insert(['ulid' => (string) Str::ulid(), 'recipient_user_id' => $other->id, 'subject_type' => $a->creator->getMorphClass(), 'subject_id' => $a->creator->id, 'type' => 'message.received', 'data' => json_encode(['x' => 1], JSON_THROW_ON_ERROR), 'created_at' => now()->toDateTimeString()]);
    $after = $run->plan($a->user);

    Artisan::call('erasure:run', ['user_ulid' => $a->user->ulid]);
    $out = Artisan::output();

    expect($after->report->cost(500)['rows'])->toBe($before->report->cost(500)['rows'] + 1)
        ->and($after->digest())->toBe($before->digest())
        ->and($out)->toContain('Cost: ')->toContain('batches of 500')->toContain('| read ')
        ->and($out)->not->toContain($a->email);
});

it('is the audit scrub the container hands out, and it is available', function (): void {
    expect(app(AuditScrub::class))->toBeInstanceOf(DatabaseAuditScrub::class)
        ->and(app(AuditScrub::class)->available())->toBeTrue();
});
