<?php

declare(strict_types=1);

use App\Modules\Erasure\Execution\ErasureEngine;
use App\Modules\Erasure\Registry\ErasureRegistry;
use App\Modules\Erasure\Registry\RegistryVerifier;
use App\Modules\Erasure\Subject\SubjectSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ErasureGraph;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * AH-119 A0 finding F2: `creators.search_vector` is a PostgreSQL-only STORED generated column
 * over display_name + bio. The registry now classifies it KEEP (derived), which is only true if
 * erasing the two source columns empties it. SQLite has no such column, so this runs on the
 * Postgres leg (`composer test:pg`).
 */

it('classifies creators.search_vector, and the stale-entry check tolerates its absence off Postgres', function (): void {
    expect(ErasureRegistry::tables()['creators']->columns)->toHaveKey('search_vector')
        ->and(RegistryVerifier::POSTGRES_ONLY_COLUMNS)->toBe(['creators' => ['search_vector']]);
});

it('leaves no lexeme of the person in the search vector once display_name and bio are erased', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('creators.search_vector exists on PostgreSQL only; covered by the Postgres leg.');
    }

    $a = ErasureGraph::seedSubject('a');
    DB::table('creators')->where('id', $a->creator->id)->update([
        'display_name' => 'Zephyrina Quillfeather',
        'bio' => 'juggles lanterns professionally',
    ]);
    $hits = fn (string $term): int => DB::table('creators')
        ->whereRaw("search_vector @@ to_tsquery('simple', ?)", [$term])->count();

    expect($hits('zephyrina'))->toBe(1)->and($hits('lanterns'))->toBe(1);

    (new ErasureEngine)->run(SubjectSet::resolve($a->user));

    $vector = (string) DB::table('creators')->where('id', $a->creator->id)->value('search_vector');
    expect($hits('zephyrina'))->toBe(0)
        ->and($hits('lanterns'))->toBe(0)
        ->and(trim($vector))->toBe('');
});
