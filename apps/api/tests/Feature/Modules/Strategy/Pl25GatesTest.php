<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Models\WaveStrategyCompletion;
use App\Modules\Strategy\Models\WaveStrategyNote;
use App\Modules\Waves\Models\Wave;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\ConceptFixtures;
use Tests\Support\Pl25Fixtures as P;
use Tests\Support\StrategyFixtures as S;
use Tests\Support\WaveFixtures;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** PL-2.5 B13, B14, B16, B18, B20. */
function p25Src(string $glob): array
{
    $out = [];
    foreach (glob(base_path($glob)) ?: [] as $file) {
        $out[$file] = (string) file_get_contents($file);
    }

    return $out;
}

function p25Recorded(Closure $fn): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $log = array_map(static fn (array $q): string => strtolower((string) $q['query']), DB::getQueryLog());
    DB::disableQueryLog();

    return $log;
}

function p25Expect(bool $ok, string $why): void
{
    expect($ok)->toBeTrue($why);
}

it('B20 / A1: every step-changing writer goes through StrategyTrackerSync::transaction; raw DB::transaction is only where no step can change', function (): void {
    $mustUse = ['UspService', 'PersonaService', 'MatrixService', 'ConceptService', 'ConceptDecisionService', 'ConceptImageService', 'ConceptLinkService', 'CompletionService'];
    foreach ($mustUse as $name) {
        $src = (string) file_get_contents(app_path("Modules/Strategy/Services/{$name}.php"));
        p25Expect(str_contains($src, 'StrategyTrackerSync::transaction('), "{$name} must call StrategyTrackerSync::transaction");
    }

    // Files that may open a plain transaction, and why: none of these changes a step's real state.
    $raw = [
        'StrategyTrackerSync' => 'the choke point itself and catch-up',
        'UspService' => 'edit, reorder, audience title: no count or approval change',
        'PersonaService' => 'edit: no count change',
        'ConceptService' => 'duplicate image finalise: the copy stays Awaiting',
        'ConceptImageService' => 'upload reservation: nothing is attached yet',
        'AngleService' => 'angles never move a step',
        'NoteService' => 'notes never move a step',
    ];
    foreach (p25Src('app/Modules/Strategy/{Services,Notes}/*.php') as $file => $src) {
        $name = basename($file, '.php');
        if (str_contains($src, 'DB::transaction(')) {
            p25Expect(isset($raw[$name]), "{$name} opens DB::transaction but is not on the audited list: a writer that can change a step must use StrategyTrackerSync::transaction");
        }
    }

    // A writer that resets an approved concept always runs inside the choke point.
    foreach (p25Src('app/Modules/Strategy/Services/*.php') as $file => $src) {
        if (str_contains($src, 'resetIfApproved(') && basename($file) !== 'ConceptService.php') {
            p25Expect(str_contains($src, 'StrategyTrackerSync::transaction('), basename($file).' resets approvals outside the choke point');
        }
    }
});

it('B16: only the Notes namespace (and the files that must know the table) touch WaveStrategyNote; no presenter outside it returns a body', function (): void {
    $allowed = ['Notes/', 'Models/WaveStrategyNote.php', 'Database/Factories/WaveStrategyNoteFactory.php', 'StrategyServiceProvider.php', 'Services/StrategyWaveMarketGuard.php'];
    $hits = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules'), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $f) {
        if ($f->getExtension() !== 'php') {
            continue;
        }
        $path = str_replace(app_path('Modules').'/', '', $f->getPathname());
        $src = (string) file_get_contents($f->getPathname());
        if (preg_match('/WaveStrategyNote\b/', $src) === 1 && ! collect($allowed)->contains(fn (string $a): bool => str_contains($path, 'Strategy/'.$a))) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe([]);

    $presenters = p25Src('app/Modules/Strategy/Http/Resources/*.php');
    foreach ($presenters as $file => $src) {
        p25Expect(! str_contains($src, 'WaveStrategyNote') && ! str_contains($src, "'body'"), basename($file).' must not read note bodies');
    }
});

it('B16: the note routes exist only under the agency strategy prefix with the web guard; no other route file knows notes', function (): void {
    $notes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), '/strategy/notes') || str_ends_with($r->uri(), '/strategy/completions/{step}'));
    expect($notes)->toHaveCount(6);
    foreach ($notes as $r) {
        expect($r->uri())->toStartWith('api/v1/agencies/{agency}/brands/{brand}/waves/{wave}/strategy/')
            ->and($r->gatherMiddleware())->toContain('auth:web');
    }
    $other = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'notes') && ! str_contains($r->uri(), '/waves/{wave}/strategy/'))
        ->map(fn ($r) => $r->uri())->values()->all();
    expect(array_filter($other, fn (string $u): bool => str_contains($u, 'strategy') || str_contains($u, 'wave')))->toBe([]);
});

it('B16: client and creator users are refused on every note and completion route; no other payload carries a note body', function (): void {
    $w = S::world();
    S::call($w['staff'], 'POST', S::u($w, 'notes'), ['step' => 'usps', 'body' => 'Internal-only-body-777'])->assertCreated();
    $creator = User::factory()->creator()->createOne();

    foreach ([['GET', 'notes?step=usps'], ['POST', 'notes'], ['PUT', 'completions/usps'], ['DELETE', 'completions/usps']] as [$m, $s]) {
        expect(S::call($creator, $m, S::u($w, $s), ['step' => 'usps', 'body' => 'x'])->status())->toBeIn([403, 404]);
    }

    foreach (['', '/tracker'] as $suffix) {
        $res = S::call($w['admin'], 'GET', WaveFixtures::wavesUrl($w['agency'], $w['brand'], $w['wave']->ulid).$suffix)->assertOk();
        expect($res->getContent())->not->toContain('Internal-only-body-777');
    }
    foreach (['usps', 'personas', 'matrix', 'concepts'] as $s) {
        $res = S::call($w['admin'], 'GET', S::u($w, $s));
        expect($res->getContent())->not->toContain('Internal-only-body-777');
    }
    expect(S::call($w['admin'], 'GET', S::u($w))->getContent())->not->toContain('Internal-only-body-777');
});

it('B13: a mark, a hook caller and a hand-set take the wave lock before they read or write any step row (query log)', function (): void {
    $w = S::world();
    P::usps($w, 1);
    P::row($w, 'ugc.usps');
    $pg = DB::getDriverName() === 'pgsql';

    $check = function (array $log, string $what) use ($pg): void {
        $firstWrite = null;
        foreach ($log as $i => $q) {
            if (preg_match('/^(insert|update|delete)/', $q) === 1) {
                $firstWrite = $i;
                break;
            }
        }
        p25Expect($firstWrite !== null, "{$what}: no write");
        $lockAt = null;
        foreach ($log as $i => $q) {
            if (str_contains($q, 'from "waves"') && (! $pg || str_contains($q, 'for update'))) {
                $lockAt = $i;
                break;
            }
        }
        p25Expect($lockAt !== null && $lockAt < $firstWrite, "{$what}: the wave lock must come before the first write");
        if ($pg) {
            foreach ($log as $i => $q) {
                if (str_contains($q, 'for update')) {
                    p25Expect($i >= $lockAt, "{$what}: a row lock before the wave lock");
                    break;
                }
            }
            $firstLock = collect($log)->first(fn (string $q): bool => str_contains($q, 'for update'));
            p25Expect(str_contains((string) $firstLock, 'from "waves"'), "{$what}: the first FOR UPDATE must be on waves");
        }
    };

    $check(p25Recorded(fn () => P::mark($w, 'usps')->assertOk()), 'mark');
    $check(p25Recorded(fn () => P::hand($w, 'ugc.usps', 'in_progress')->assertOk()), 'hand-set on a strategy row');
    $check(p25Recorded(fn () => P::usps($w, 1)), 'usp create (hook caller)');
});

it('B13: two writers one after the other end consistent (a decision, then a hand-set of the same row)', function (): void {
    $w = S::world();
    S::keepMarkets($w, ['SK'])->assertOk();
    [$p] = P::personas($w, 'SK');
    $c = P::concept($w, 'SK', $p);

    P::hand($w, 'ugc.concepts', 'done')->assertStatus(409);
    ConceptFixtures::decide($w, $c)->assertOk();
    P::hand($w, 'ugc.concepts', 'done')->assertOk();

    expect(P::st($w, 'ugc.concepts'))->toBe(['done', 'system']);
});

it('B14: the wave list stays one grouped query on wave_steps and touches no strategy table; the summary and tracker stay under a stated maximum', function (): void {
    $w = S::world();
    P::usps($w, 2);
    P::personas($w, 'SK', 2);

    $log = p25Recorded(fn () => S::call($w['admin'], 'GET', WaveFixtures::wavesUrl($w['agency'], $w['brand']))->assertOk());
    expect(count(array_filter($log, fn (string $q): bool => str_contains($q, 'wave_steps'))))->toBeLessThanOrEqual(1)
        ->and(array_filter($log, fn (string $q): bool => (bool) preg_match('/wave_(usps|personas|matrix|concepts|strategy_)/', $q)))->toBe([]);

    $summary = p25Recorded(fn () => S::call($w['admin'], 'GET', S::u($w))->assertOk());
    $tracker = p25Recorded(fn () => S::call($w['admin'], 'GET', WaveFixtures::wavesUrl($w['agency'], $w['brand'], $w['wave']->ulid).'/tracker')->assertOk());
    expect(count($summary))->toBeLessThanOrEqual(60)->and(count($tracker))->toBeLessThanOrEqual(45);
});

it('B18: both tables are created and dropped; the database refuses a mark with a market of another wave and a second mark of one scope', function (): void {
    $w = S::world();
    expect(Schema::hasTable('wave_strategy_completions'))->toBeTrue()->and(Schema::hasTable('wave_strategy_notes'))->toBeTrue();

    $row = fn (array $o): array => array_merge([
        'ulid' => (string) Str::ulid(), 'agency_id' => $w['agency']->id, 'wave_id' => $w['wave']->id, 'step' => 'personas',
        'market_id' => $w['mkid']['SK'], 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ], $o);
    $refuse = function (Closure $fn): void {
        try {
            DB::transaction($fn);
            $failed = false;
        } catch (QueryException) {
            $failed = true;
        }
        expect($failed)->toBeTrue();
    };

    $other = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->withMarkets([['name' => 'Poland', 'code' => 'PL', 'countries' => ['PL']]])->create();
    $foreign = (int) DB::table('wave_markets')->where('wave_id', $other->id)->value('id');
    $refuse(fn () => DB::table('wave_strategy_completions')->insert($row(['market_id' => $foreign])));

    DB::table('wave_strategy_completions')->insert($row([]));
    $refuse(fn () => DB::table('wave_strategy_completions')->insert($row([])));
    DB::table('wave_strategy_completions')->insert($row(['step' => 'usps', 'market_id' => null]));
    $refuse(fn () => DB::table('wave_strategy_completions')->insert($row(['step' => 'usps', 'market_id' => null])));

    if (DB::getDriverName() === 'pgsql') {
        $refuse(fn () => DB::table('wave_strategy_completions')->insert($row(['step' => 'concepts', 'market_id' => $w['mkid']['RO']])));
        $refuse(fn () => DB::table('wave_strategy_completions')->insert($row(['step' => 'usps', 'market_id' => $w['mkid']['RO']])));
        $refuse(fn () => DB::table('wave_strategy_completions')->insert($row(['step' => 'matrix', 'market_id' => null])));
        $refuse(fn () => DB::table('wave_strategy_notes')->insert(['ulid' => (string) Str::ulid(), 'agency_id' => $w['agency']->id, 'wave_id' => $w['wave']->id, 'step' => 'creators', 'market_id' => null, 'body' => 'x', 'created_at' => now(), 'updated_at' => now()]));
    }

    foreach (['2026_11_22_100001_create_wave_strategy_notes_table', '2026_11_22_100000_create_wave_strategy_completions_table'] as $file) {
        $m = require database_path("migrations/{$file}.php");
        $m->down();
    }
    expect(Schema::hasTable('wave_strategy_completions'))->toBeFalse()->and(Schema::hasTable('wave_strategy_notes'))->toBeFalse();
    foreach (['2026_11_22_100000_create_wave_strategy_completions_table', '2026_11_22_100001_create_wave_strategy_notes_table'] as $file) {
        $m = require database_path("migrations/{$file}.php");
        $m->up();
    }
    expect(Schema::hasTable('wave_strategy_completions'))->toBeTrue()->and(WaveStrategyCompletion::query()->count())->toBe(0)->and(WaveStrategyNote::query()->count())->toBe(0);
});
