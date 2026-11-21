<?php

declare(strict_types=1);

use App\Modules\Strategy\Enums\ConceptOrigin;
use App\Modules\Strategy\Enums\ConceptStatus;
use App\Modules\Strategy\Enums\FunnelStage;
use App\Modules\Strategy\Models\WaveConcept;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Waves\Database\Factories\WaveFactory;
use App\Modules\Waves\Models\Wave;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-2.3 — the five concept tables and their database guards (plan section 1.2): the composite foreign keys (same wave,
 * same market), RESTRICT, unique keys, the PostgreSQL CHECKs, and no user column on the text tables. A statement expected
 * to fail runs inside a nested transaction (a savepoint). These run on SQLite and PostgreSQL.
 */

/** @return array<string, mixed> */
function conceptRow(WaveConcept $c, array $extra = []): array
{
    return array_merge(['ulid' => (string) Str::ulid(), 'agency_id' => $c->agency_id, 'wave_id' => $c->wave_id, 'market_id' => $c->market_id, 'concept_id' => $c->id, 'created_at' => now(), 'updated_at' => now()], $extra);
}

/** A statement expected to fail, run in a savepoint so PostgreSQL's aborted-transaction state does not leak. */
function failing(Closure $statement): Closure
{
    return function () use ($statement): void {
        DB::transaction(function () use ($statement): void {
            $statement();
        });
    };
}

it('CS1: the tables have the planned columns; the text tables carry no creator or user column', function (): void {
    expect(Schema::hasColumns('wave_concepts', ['id', 'ulid', 'agency_id', 'wave_id', 'market_id', 'persona_id', 'position', 'title', 'funnel_stage', 'funnel_note', 'product', 'angle', 'primary_message', 'hypothesis', 'hook', 'format_visual', 'key_points', 'payoff', 'cta', 'status', 'origin', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('wave_concept_usps', ['id', 'agency_id', 'wave_id', 'market_id', 'concept_id', 'usp_id', 'position']))->toBeTrue()
        ->and(Schema::hasColumns('wave_concept_images', ['id', 'ulid', 'concept_id', 'storage_key', 'thumb_key', 'original_filename', 'mime_type', 'declared_bytes', 'size_bytes', 'status', 'expires_at', 'position', 'uploaded_by_user_id']))->toBeTrue()
        ->and(Schema::hasColumns('wave_concept_links', ['id', 'ulid', 'concept_id', 'url', 'title', 'position']))->toBeTrue()
        ->and(Schema::hasColumns('wave_concept_decisions', ['id', 'ulid', 'concept_id', 'status', 'client_name', 'note', 'comment', 'source', 'recorded_by_user_id', 'recorded_at']))->toBeTrue();
    foreach (['wave_concepts', 'wave_concept_usps', 'wave_concept_links'] as $table) {
        expect(Schema::hasColumn($table, 'created_by_user_id'))->toBeFalse()->and(Schema::hasColumn($table, 'user_id'))->toBeFalse();
    }

    $c = WaveConcept::query()->findOrFail(WaveConcept::factory()->create()->id);
    expect($c->status->value)->toBe('awaiting')->and($c->origin->value)->toBe('manual')->and($c->position)->toBe(1);
});

it('CS2: the persona of a concept must be a persona of the SAME wave and market (composite foreign key)', function (): void {
    $wave = Wave::factory()->withMarkets(WaveFactory::singleCountryMarkets(['SK', 'RO']))->create();
    $skId = WaveFactory::marketId($wave, 'SK');
    $sk = WavePersona::factory()->create(['wave_id' => $wave->id, 'agency_id' => $wave->agency_id, 'market_id' => $skId]);
    $ro = WavePersona::factory()->create(['wave_id' => $wave->id, 'agency_id' => $wave->agency_id, 'market_id' => WaveFactory::marketId($wave, 'RO')]);
    $other = WavePersona::factory()->create();
    $base = ['agency_id' => $wave->agency_id, 'wave_id' => $wave->id, 'position' => 1, 'title' => 'x', 'status' => 'awaiting', 'origin' => 'manual', 'created_at' => now(), 'updated_at' => now()];
    $insert = fn (array $row) => failing(fn () => DB::table('wave_concepts')->insert($row + $base));

    expect($insert(['ulid' => (string) Str::ulid(), 'market_id' => $skId, 'persona_id' => $ro->id]))->toThrow(QueryException::class);
    expect($insert(['ulid' => (string) Str::ulid(), 'market_id' => $skId, 'persona_id' => $other->id]))->toThrow(QueryException::class);
    DB::table('wave_concepts')->insert(['ulid' => (string) Str::ulid(), 'market_id' => $skId, 'persona_id' => $sk->id] + $base);
    expect(WaveConcept::query()->count())->toBe(1);
});

it('CS3: a chip is a USP of the concept\'s wave, once per concept; its concept is in the same market', function (): void {
    $c = WaveConcept::factory()->create();
    $usp = WaveUsp::factory()->create(['wave_id' => $c->wave_id, 'agency_id' => $c->agency_id]);
    $foreign = WaveUsp::factory()->create();
    $chip = fn (int $uspId, ?int $marketId = null) => failing(fn () => DB::table('wave_concept_usps')->insert([
        'agency_id' => $c->agency_id, 'wave_id' => $c->wave_id, 'market_id' => $marketId ?? $c->market_id, 'concept_id' => $c->id, 'usp_id' => $uspId, 'position' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]));

    expect($chip($foreign->id))->toThrow(QueryException::class);
    expect($chip($usp->id, 999999))->toThrow(QueryException::class);
    $chip($usp->id)();
    expect($chip($usp->id))->toThrow(UniqueConstraintViolationException::class);
    expect(DB::table('wave_concept_usps')->count())->toBe(1);
});

it('CS4: images, links and decisions are children of a concept of the same wave and market', function (): void {
    $c = WaveConcept::factory()->create();
    $wrongMarket = conceptRow($c, ['market_id' => 999999]);
    $wrongWave = conceptRow($c, ['wave_id' => Wave::factory()->create()->id]);

    foreach ([
        'wave_concept_links' => ['url' => 'https://example.com', 'title' => 't', 'position' => 1],
        'wave_concept_decisions' => ['status' => 'approved', 'client_name' => 'A', 'source' => 'agency', 'recorded_at' => now()],
        'wave_concept_images' => ['storage_key' => 'k', 'original_filename' => 'a.png', 'declared_bytes' => 1, 'status' => 'pending', 'position' => 1],
    ] as $table => $extra) {
        expect(failing(fn () => DB::table($table)->insert($wrongMarket + $extra)))->toThrow(QueryException::class);
        expect(failing(fn () => DB::table($table)->insert($wrongWave + $extra)))->toThrow(QueryException::class);
        DB::table($table)->insert(conceptRow($c) + $extra);
        expect(DB::table($table)->count())->toBe(1);
    }
});

it('CS5: image keys are unique (storage_key, and thumb_key when set)', function (): void {
    $c = WaveConcept::factory()->create();
    $img = fn (string $key, ?string $thumb) => failing(fn () => DB::table('wave_concept_images')->insert(conceptRow($c) + ['storage_key' => $key, 'thumb_key' => $thumb, 'original_filename' => 'a.png', 'declared_bytes' => 1, 'status' => 'pending', 'position' => 1]));

    $img('a', 't1')();
    expect($img('a', null))->toThrow(UniqueConstraintViolationException::class);
    expect($img('b', 't1'))->toThrow(UniqueConstraintViolationException::class);
    $img('c', null)();
    $img('d', null)();
    expect(DB::table('wave_concept_images')->count())->toBe(3);
});

it('CS6: RESTRICT: a persona, a USP, a concept or a wave that concepts use cannot be hard-deleted', function (): void {
    $c = WaveConcept::factory()->create();
    $usp = WaveUsp::factory()->create(['wave_id' => $c->wave_id, 'agency_id' => $c->agency_id]);
    DB::table('wave_concept_usps')->insert(['agency_id' => $c->agency_id, 'wave_id' => $c->wave_id, 'market_id' => $c->market_id, 'concept_id' => $c->id, 'usp_id' => $usp->id, 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('wave_concept_links')->insert(conceptRow($c) + ['url' => 'https://example.com', 'title' => 't', 'position' => 1]);

    foreach (['wave_personas' => $c->persona_id, 'wave_usps' => $usp->id, 'waves' => $c->wave_id, 'wave_concepts' => $c->id] as $table => $id) {
        expect(failing(fn () => DB::table($table)->where('id', $id)->delete()))->toThrow(QueryException::class);
    }
    expect(WaveConcept::query()->count())->toBe(1);
});

it('CS7: PostgreSQL: the CHECKs refuse an unknown status, funnel stage, MIME type and a malformed decision', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The CHECK constraints are PostgreSQL only; the PHP enums are the constraint elsewhere.');
    }
    $c = WaveConcept::factory()->create();
    $concept = fn (array $o) => failing(fn () => DB::table('wave_concepts')->where('id', $c->id)->update($o));
    expect($concept(['status' => 'declined']))->toThrow(QueryException::class);
    expect($concept(['funnel_stage' => 'middle']))->toThrow(QueryException::class);
    expect($concept(['origin' => 'ai']))->toThrow(QueryException::class);

    $image = fn (array $o) => failing(fn () => DB::table('wave_concept_images')->insert(conceptRow($c) + $o + ['storage_key' => 'k'.random_int(1, 99999), 'original_filename' => 'a', 'declared_bytes' => 1, 'status' => 'pending', 'position' => 1]));
    expect($image(['mime_type' => 'image/gif']))->toThrow(QueryException::class);
    expect($image(['mime_type' => 'image/svg+xml']))->toThrow(QueryException::class);
    expect($image(['status' => 'done']))->toThrow(QueryException::class);

    $decision = fn (array $o) => failing(fn () => DB::table('wave_concept_decisions')->insert(conceptRow($c) + $o + ['source' => 'agency', 'recorded_at' => now()]));
    expect($decision(['status' => 'awaiting', 'client_name' => 'A']))->toThrow(QueryException::class);
    expect($decision(['status' => 'changes_requested', 'client_name' => 'A', 'comment' => null]))->toThrow(QueryException::class);
    expect($decision(['status' => 'approved', 'client_name' => 'A', 'comment' => 'not allowed']))->toThrow(QueryException::class);
    $decision(['status' => 'approved', 'client_name' => 'A', 'note' => 'fine'])();
    $decision(['status' => 'changes_requested', 'client_name' => 'A', 'comment' => 'c'])();
    expect(DB::table('wave_concept_decisions')->count())->toBe(2);
});

it('CS8: the migrations are schema-only: no data statement in any of the five files, each sets a lock timeout first', function (): void {
    foreach (glob(database_path('migrations/2026_11_21_2000*.php')) ?: [] as $file) {
        $source = (string) file_get_contents($file);
        expect($source)->not->toMatch('/DB::table\(|->insert\(|->update\(|->delete\(|insertOrIgnore|upsert\(|Model::|(?<!Schema)::create\(|DB::unprepared\(/')
            ->and($source)->toContain("SET LOCAL lock_timeout = '5s'")
            ->and($source)->toContain('@migration-risk low');
    }
});

it('CS9: every status and key column is wide enough for the longest value its enum can hold (PostgreSQL enforces the width; SQLite does not)', function (): void {
    $longest = static fn (array $cases): int => max(0, ...array_map(static fn (BackedEnum $c): int => strlen((string) $c->value), $cases));
    $width = static function (string $table, string $column): ?int {
        if (DB::getDriverName() !== 'pgsql') {
            return null;
        }

        return (int) DB::table('information_schema.columns')->where('table_name', $table)->where('column_name', $column)->value('character_maximum_length');
    };

    foreach ([
        ['wave_concepts', 'status', ConceptStatus::cases()],
        ['wave_concept_decisions', 'status', [ConceptStatus::Approved, ConceptStatus::ChangesRequested]],
        ['wave_concepts', 'funnel_stage', FunnelStage::cases()],
        ['wave_concepts', 'origin', ConceptOrigin::cases()],
    ] as [$table, $column, $cases]) {
        $w = $width($table, $column);
        if ($w !== null) {
            expect([$table.'.'.$column => $w >= $longest($cases)])->toBe([$table.'.'.$column => true]);
        }
    }
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Column widths are enforced on PostgreSQL only.');
    }
});
