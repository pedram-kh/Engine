<?php

declare(strict_types=1);

use App\Modules\Erasure\Selection\DryRun;
use App\Modules\Erasure\Selection\DryRunReport;
use App\Modules\Erasure\Selection\Selections;
use App\Modules\Erasure\Subject\SubjectSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ErasureGraph;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** @return array<string, string> table => md5 of every row, in a stable order */
function tableFingerprints(): array
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

it('counts each selection exactly, for two symmetric creators (a leak doubles a count, a miss halves it)', function (): void {
    $a = ErasureGraph::seedSubject('a');
    $b = ErasureGraph::seedSubject('b');

    $reportA = (new DryRun)->run(SubjectSet::resolve($a->user));
    $reportB = (new DryRun)->run(SubjectSet::resolve($b->user));

    expect($reportA->counts())->toBe(ErasureGraph::expectedCounts())
        ->and($reportB->counts())->toBe(ErasureGraph::expectedCounts());
});

it('has an expected count for every selection, and none is vacuous', function (): void {
    $expected = ErasureGraph::expectedCounts();
    $ids = array_map(fn ($s): string => $s->id, Selections::all());
    sort($ids);
    $keys = array_keys($expected);
    sort($keys);

    expect($keys)->toBe($ids)
        ->and(array_filter($expected, fn (int $n): bool => $n < 1))->toBe([]);
});

it('writes nothing: SELECT statements only, and every table is byte-identical afterwards', function (): void {
    $graph = ErasureGraph::seedSubject('a');
    ErasureGraph::seedSubject('b');
    $subject = SubjectSet::resolve($graph->user);

    $before = tableFingerprints();
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    (new DryRun)->run($subject);

    $nonSelect = array_values(array_filter($statements, fn (string $sql): bool => ! preg_match('/^\s*select\b/i', $sql)));

    expect($statements)->not->toBe([])
        ->and($nonSelect)->toBe([])
        ->and(tableFingerprints())->toBe($before);
});

it('is repeatable: a second dry-run reports the same counts and the same digest', function (): void {
    $subject = SubjectSet::resolve(ErasureGraph::seedSubject('a')->user);

    $first = (new DryRun)->run($subject);
    $second = (new DryRun)->run($subject);

    expect($second->counts())->toBe($first->counts())
        ->and($second->digest())->toBe($first->digest());
});

it('changes its digest when the data moves between dry-run and execute', function (): void {
    $graph = ErasureGraph::seedSubject('a');
    $subject = SubjectSet::resolve($graph->user);
    $before = (new DryRun)->run($subject)->digest();

    DB::table('features')->insert(['name' => 'late-flag', 'scope' => $graph->user->getMorphClass().'|'.$graph->user->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()]);

    expect((new DryRun)->run($subject)->digest())->not->toBe($before);
});

// AH-104c C2-0: the claim revokes every session and token before the execute recomputes the digest, so
// the credential tables are printed but not hashed. Break-revert: hash them again and this reds, and so
// does every execute of an account that had a session.
it('does not move its digest when a credential row appears or goes, but still prints the count', function (): void {
    $graph = ErasureGraph::seedSubject('a');
    $subject = SubjectSet::resolve($graph->user);
    $report = (new DryRun)->run($subject);
    $before = $report->digest();
    $sessionRows = static fn (DryRunReport $r): int => array_sum(array_column(array_filter($r->lines, static fn (array $line): bool => $line['table'] === 'sessions'), 'count'));

    DB::table('sessions')->insert(['id' => 'late-session', 'user_id' => $graph->user->id, 'ip_address' => '203.0.113.9', 'user_agent' => 'phpunit', 'payload' => 'x', 'last_activity' => time()]);
    $after = (new DryRun)->run($subject);

    expect($after->digest())->toBe($before)
        ->and($sessionRows($after))->toBe($sessionRows($report) + 1);

    DB::table('sessions')->where('user_id', $graph->user->id)->delete();

    expect((new DryRun)->run($subject)->digest())->toBe($before);
});

it('prints no personal data: no email, name, phone, ip, user agent or row id', function (): void {
    $graph = ErasureGraph::seedSubject('a');
    $report = (new DryRun)->run(SubjectSet::resolve($graph->user));

    $json = json_encode($report->toArray(), JSON_THROW_ON_ERROR);

    foreach ([$graph->email, 'Subject a', 'Display a', '+39 333', '203.0.113.9', $graph->kycSessionId] as $needle) {
        expect($json)->not->toContain($needle);
    }
});
