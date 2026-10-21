<?php

declare(strict_types=1);

use App\Modules\Creators\Models\CreatorTaxProfile;
use App\Modules\Erasure\Execution\ErasureEngine;
use App\Modules\Erasure\Execution\Steps;
use App\Modules\Erasure\Registry\Disposition;
use App\Modules\Erasure\Registry\ErasureRegistry;
use App\Modules\Erasure\Selection\DryRun;
use App\Modules\Erasure\Selection\Selections;
use App\Modules\Erasure\Subject\SubjectSet;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ErasureGraph;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const STAGES_4A_4D = ['4a', '4b', '4c', '4d'];

/** @return list<string> the selection ids the ENGINE executes (4a to 4d): not the audit scrub, not the rider's cancellations */
function engineSelectionIds(): array
{
    $ids = [];
    foreach (Selections::all() as $selection) {
        if (in_array($selection->stage, STAGES_4A_4D, true)) {
            $ids[] = $selection->id;
        }
    }

    return $ids;
}

/** @return array<string, int> every selection the engine executes => expected count */
function dbStageExpected(): array
{
    return array_intersect_key(ErasureGraph::expectedCounts(), array_flip(engineSelectionIds()));
}

/** @return array<string, string> table => md5 of every row, in a stable order */
function engineTableDigests(): array
{
    $out = [];
    foreach (Schema::getTables() as $table) {
        $name = $table['name'];
        $rows = DB::table($name)->get()->map(fn ($r): string => json_encode($r, JSON_THROW_ON_ERROR))->sort()->values()->all();
        $out[$name] = md5(json_encode($rows, JSON_THROW_ON_ERROR));
    }
    ksort($out);

    return $out;
}

/** @return array<string, int> */
function engineRowCounts(): array
{
    $out = [];
    foreach (Schema::getTables() as $table) {
        $out[$table['name']] = DB::table($table['name'])->count();
    }
    ksort($out);

    return $out;
}

/** @return array<string, int> the dry-run counts of the database stages only */
function dbStageDryRun(SubjectSet $subject): array
{
    return array_intersect_key((new DryRun)->run($subject)->counts(), array_flip(engineSelectionIds()));
}

it('does what its dry-run said: the executed counts equal the dry-run counts, key for key', function (): void {
    $a = ErasureGraph::seedSubject('a');
    ErasureGraph::seedSubject('b');
    $subject = SubjectSet::resolve($a->user);

    $planned = dbStageDryRun($subject);
    $report = (new ErasureEngine)->run($subject);

    expect($planned)->toBe(dbStageExpected())
        ->and($report->counts())->toBe($planned);
});

it('is idempotent: afterwards a dry-run counts nothing, and a second run changes nothing at all', function (): void {
    $a = ErasureGraph::seedSubject('a');
    ErasureGraph::seedSubject('b');
    $subject = SubjectSet::resolve($a->user);
    (new ErasureEngine)->run($subject);

    $after = engineTableDigests();
    $again = (new ErasureEngine)->run($subject);

    expect(array_sum(dbStageDryRun($subject)))->toBe(0)
        ->and($again->total())->toBe(0)
        ->and(engineTableDigests())->toBe($after);
});

it('leaves the bystander creator untouched: same counts, and none of its tables changed by the run', function (): void {
    $a = ErasureGraph::seedSubject('a');
    $b = ErasureGraph::seedSubject('b');
    $bystander = SubjectSet::resolve($b->user);
    $bystanderBefore = dbStageDryRun($bystander);
    $bUserBefore = (array) DB::table('users')->where('id', $b->user->id)->first();
    $bCreatorBefore = (array) DB::table('creators')->where('id', $b->creator->id)->first();

    (new ErasureEngine)->run(SubjectSet::resolve($a->user));

    expect(dbStageDryRun($bystander))->toBe($bystanderBefore)
        ->and($bystanderBefore)->toBe(dbStageExpected())
        ->and((array) DB::table('users')->where('id', $b->user->id)->first())->toBe($bUserBefore)
        ->and((array) DB::table('creators')->where('id', $b->creator->id)->first())->toBe($bCreatorBefore);
});

it('deletes only what an ERASE-ROW or DEL-MATCH selection reports, and never a row of any other table (anchors stay)', function (): void {
    $a = ErasureGraph::seedSubject('a');
    ErasureGraph::seedSubject('b');
    $before = engineRowCounts();

    $report = (new ErasureEngine)->run(SubjectSet::resolve($a->user));

    $deletedPerTable = [];
    foreach (Selections::all() as $selection) {
        if (in_array($selection->stage, STAGES_4A_4D, true) && in_array($selection->disposition, [Disposition::EraseRow, Disposition::DelMatch], true)) {
            $deletedPerTable[$selection->table] = ($deletedPerTable[$selection->table] ?? 0) + ($report->changed[$selection->id] ?? 0);
        }
    }
    $expected = $before;
    foreach ($deletedPerTable as $table => $n) {
        $expected[$table] -= $n;
    }

    expect(engineRowCounts())->toBe($expected)
        ->and(DB::table('users')->where('id', $a->user->id)->exists())->toBeTrue()
        ->and(DB::table('creators')->where('id', $a->creator->id)->exists())->toBeTrue();
});

it('leaves the agency member\'s own rows byte-identical (what they sent, and their account)', function (): void {
    $a = ErasureGraph::seedSubject('a');
    $member = $a->agencyMember->id;
    $own = fn (): array => [
        (array) DB::table('users')->where('id', $member)->first(),
        DB::table('messages')->where('sender_user_id', $member)->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all(),
        DB::table('relationship_messages')->where('sender_user_id', $member)->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all(),
        DB::table('notifications')->where('recipient_user_id', $member)->whereNotNull('data')->orderBy('id')->pluck('id')->all(),
    ];
    $before = $own();

    (new ErasureEngine)->run(SubjectSet::resolve($a->user));

    $after = $own();
    expect($after)->toBe($before)
        ->and($after[1])->not->toBe([]);
});

it('leaves no trace of the person outside audit_logs: email, name, display name and phone', function (): void {
    $a = ErasureGraph::seedSubject('a');
    ErasureGraph::seedSubject('b');
    (new ErasureEngine)->run(SubjectSet::resolve($a->user));

    $needles = [
        'email' => '/(?<![A-Za-z0-9._%+-])'.preg_quote($a->email, '/').'/i',
        'name' => '/Subject a(?![A-Za-z0-9])/',
        'display_name' => '/Display a(?![A-Za-z0-9])/',
        'phone' => '/'.preg_quote((string) $a->creator->phone, '/').'/',
    ];

    $found = [];
    foreach (Schema::getTables() as $table) {
        $name = $table['name'];
        if ($name === 'audit_logs') {
            continue; // the audit scrub is Stage 4e
        }
        foreach (DB::table($name)->get() as $row) {
            foreach ((array) $row as $column => $value) {
                if (! is_string($value)) {
                    continue;
                }
                foreach ($needles as $label => $pattern) {
                    if (preg_match($pattern, $value) === 1) {
                        $found[] = "{$name}.{$column} still holds the {$label}";
                    }
                }
            }
        }
    }

    expect($found)->toBe([]);
});

it('anonymises the identity: placeholder email, marker on both rows, the tax profile is the sentinel, and the keys stay', function (): void {
    $a = ErasureGraph::seedSubject('a');
    $ulid = $a->user->ulid;
    (new ErasureEngine)->run(SubjectSet::resolve($a->user));

    $user = User::withTrashed()->findOrFail($a->user->id);
    $tax = CreatorTaxProfile::query()->where('creator_id', $a->creator->id)->firstOrFail();
    $creator = (array) DB::table('creators')->where('id', $a->creator->id)->first();

    expect($user->email)->toBe('erased-'.strtolower($ulid).'@erased.invalid')
        ->and($user->name)->toBe('[erased]')
        ->and($user->isErased())->toBeTrue()
        ->and($user->ulid)->toBe($ulid)
        ->and($tax->legal_name)->toBe('[erased]')
        ->and($tax->tax_id)->toBe('[erased]')
        ->and($tax->address)->toBe([])
        ->and($creator['erased_at'] !== null)->toBeTrue()
        ->and($creator['display_name'])->toBeNull()
        ->and($creator['is_discoverable'])->toBe(0);
});

it('gives each erased person a distinct placeholder, so the unique email index never collides', function (): void {
    $a = ErasureGraph::seedSubject('a');
    $b = ErasureGraph::seedSubject('b');
    (new ErasureEngine)->run(SubjectSet::resolve($a->user));
    (new ErasureEngine)->run(SubjectSet::resolve($b->user));

    $emails = DB::table('users')->whereIn('id', [$a->user->id, $b->user->id])->pluck('email')->all();

    expect($emails)->toHaveCount(2)
        ->and(array_unique($emails))->toHaveCount(2);
});

it('writes the two marker rows last, so erased_at is set only after every other step', function (): void {
    $a = ErasureGraph::seedSubject('a');
    $order = [];
    (new ErasureEngine(function (string $id) use (&$order): void {
        $order[] = $id;
    }))->run(SubjectSet::resolve($a->user));

    expect(array_slice($order, -2))->toBe(['users', 'creators'])
        ->and($order)->toHaveCount(count(dbStageExpected()));
});

it('is atomic: a failure after any step, even the user marker, rolls the whole run back', function (string $failAfter): void {
    $a = ErasureGraph::seedSubject('a');
    $subject = SubjectSet::resolve($a->user);
    $before = engineTableDigests();

    $run = fn () => (new ErasureEngine(function (string $id) use ($failAfter): void {
        if ($id === $failAfter) {
            throw new RuntimeException('boom');
        }
    }))->run($subject);

    expect($run)->toThrow(RuntimeException::class, 'boom')
        ->and(engineTableDigests())->toBe($before)
        ->and(User::withTrashed()->findOrFail($a->user->id)->isErased())->toBeFalse();
})->with(['first step' => ['sessions'], 'middle step' => ['creator_social_accounts'], 'after the user row' => ['users']]);

it('refuses a stage it cannot execute (audit scrub, files, vendors, the rider\'s cancellations)', function (string $stage): void {
    $subject = SubjectSet::resolve(ErasureGraph::seedSubject('a')->user);
    $before = engineTableDigests();

    expect(fn () => (new ErasureEngine)->run($subject, null, [$stage]))->toThrow(InvalidArgumentException::class)
        ->and(engineTableDigests())->toBe($before);
})->with(['4e', '4f', '4g', '4h']);

it('has a writer for every selection of 4a to 4d, and no writer for anything else', function (): void {
    $ids = [];
    foreach (Selections::all() as $selection) {
        if (in_array($selection->stage, STAGES_4A_4D, true)) {
            $ids[] = $selection->id;
        }
    }
    $writers = array_keys(Steps::writers());
    sort($ids);
    sort($writers);

    expect($writers)->toBe($ids);
});

it('writes through the raw query builder only: no model, no save, no Eloquent write (the Audited trait would copy the PII into audit_logs)', function (): void {
    $violations = [];
    foreach (glob(app_path('Modules/Erasure/Execution/*.php')) ?: [] as $file) {
        $source = (string) file_get_contents($file);
        foreach ([
            '/use App\\\\Modules\\\\[A-Za-z]+\\\\Models\\\\/' => 'imports a model',
            '/->save\(|->saveQuietly\(|->forceFill\(|->fill\(|::create\(|::updateOrCreate\(|::firstOrCreate\(|->touch\(/' => 'calls an Eloquent write',
        ] as $pattern => $why) {
            if (preg_match($pattern, $source) === 1) {
                $violations[] = basename($file)." {$why}";
            }
        }
    }

    expect($violations)->toBe([]);
});

it('keeps every KEEP column of every row it anonymises or scrubs (the other parties\' decision records, D-D, and the lifecycle facts)', function (): void {
    $a = ErasureGraph::seedSubject('a');
    DB::table('users')->where('id', $a->user->id)->update(['suspended_reason' => 'chargeback fraud', 'is_suspended' => true]);
    DB::table('creators')->where('id', $a->creator->id)->update(['rejection_reason' => 'portfolio did not meet the bar']);
    $subject = SubjectSet::resolve($a->user);

    $keepColumns = [];
    $before = [];
    foreach (Selections::all() as $selection) {
        if (! in_array($selection->stage, STAGES_4A_4D, true) || ! in_array($selection->disposition, [Disposition::Anon, Disposition::ScrubJson], true)) {
            continue;
        }
        foreach (ErasureRegistry::tables()[$selection->table]->columns as $column => $rule) {
            if ($rule->disposition === Disposition::Keep) {
                $keepColumns[$selection->table][] = $column;
            }
        }
        foreach ($selection->builder($subject, DB::connection())->get() as $row) {
            $before[$selection->table][(string) $row->id] = (array) $row;
        }
    }

    (new ErasureEngine)->run($subject);

    $changed = [];
    foreach ($before as $table => $rows) {
        foreach ($rows as $id => $old) {
            $new = (array) DB::table($table)->where('id', $id)->first();
            foreach ($keepColumns[$table] ?? [] as $column) {
                if (($old[$column] ?? null) !== ($new[$column] ?? null)) {
                    $changed[] = "{$table}#{$id}.{$column} was changed";
                }
            }
        }
    }

    expect($before)->not->toBe([])
        ->and($changed)->toBe([])
        ->and(DB::table('users')->where('id', $a->user->id)->value('suspended_reason'))->toBe('chargeback fraud')
        ->and(DB::table('creators')->where('id', $a->creator->id)->value('rejection_reason'))->toBe('portfolio did not meet the bar');
});

it('removes only the personal keys from a JSON column: the rest of the payload, and other people in it, stay', function (): void {
    $a = ErasureGraph::seedSubject('a');
    (new ErasureEngine)->run(SubjectSet::resolve($a->user));

    $actorRow = DB::table('notifications')->where('actor_user_id', $a->user->id)->where('recipient_user_id', $a->agencyMember->id)->get()
        ->map(fn ($r): array => (array) json_decode((string) $r->data, true))->all();
    $failures = (array) json_decode((string) DB::table('tracked_jobs')->value('result'), true);
    $events = DB::table('integration_events')->orderBy('provider_event_id')->pluck('payload')->all();

    expect($actorRow)->toContain(['campaign_name' => 'C'])
        ->and($actorRow)->toContain(['campaign_name' => 'no name key here'])
        ->and($failures['failures'])->toBe([['row' => 2, 'email' => null, 'reason' => 'x'], ['row' => 3, 'email' => 'other-a@example.test', 'reason' => 'y']])
        ->and(json_decode((string) $events[0], true))->toBe(['erased' => true])
        ->and(json_decode((string) $events[2], true))->toBe(['session' => 'unrelated-session-id-1234']);
});
