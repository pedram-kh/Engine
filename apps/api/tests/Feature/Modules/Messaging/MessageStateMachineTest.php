<?php

declare(strict_types=1);

use App\Modules\Campaigns\Enums\AssignmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/MessageRevisionFixtures.php';

/*
 * AH-119 B2 — every edge of the inventory §3 state machine, refused ones included, on every surface:
 *
 *   sent --edit--> edited        edited --edit--> edited (original unchanged)
 *   sent --delete--> deleted     edited --delete--> deleted     deleted --anything--> refused
 *
 * A refused transition leaves the row exactly as it was.
 */

afterEach(fn () => Carbon::setTestNow());

/** @return array{state: string, body: ?string, original: ?string, deleted_body: ?string, edited: bool, deleted: bool} */
function revState(string $table, string $ulid): array
{
    $r = revRow($table, $ulid);

    return [
        'state' => $r->deleted_at !== null ? 'deleted' : ($r->edited_at !== null ? 'edited' : 'sent'),
        'body' => $r->body, 'original' => $r->original_body, 'deleted_body' => $r->deleted_body,
        'edited' => $r->edited_at !== null, 'deleted' => $r->deleted_at !== null,
    ];
}

it('sent --edit--> edited: the first edit saves the original and stamps edited_at', function (string $surface): void {
    $s = revSurface(revWorld(), $surface, 'v1');
    expect(revState($s['table'], $s['message']->ulid)['state'])->toBe('sent');

    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'v2'])->assertOk();

    expect(revState($s['table'], $s['message']->ulid))->toMatchArray(['state' => 'edited', 'body' => 'v2', 'original' => 'v1', 'deleted_body' => null]);
})->with(revSurfaceDataset());

it('edited --edit--> edited: the original is unchanged', function (string $surface): void {
    $s = revSurface(revWorld(), $surface, 'v1');
    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'v2'])->assertOk();

    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'v3'])->assertOk();

    expect(revState($s['table'], $s['message']->ulid))->toMatchArray(['state' => 'edited', 'body' => 'v3', 'original' => 'v1']);
})->with(revSurfaceDataset());

it('sent --delete--> deleted (terminal): the body moves to deleted_body', function (string $surface): void {
    $s = revSurface(revWorld(), $surface, 'v1');

    $this->actingAs($s['actor'])->deleteJson($s['url'])->assertOk();

    expect(revState($s['table'], $s['message']->ulid))->toMatchArray(['state' => 'deleted', 'body' => null, 'original' => null, 'deleted_body' => 'v1']);
})->with(revSurfaceDataset());

it('edited --delete--> deleted: both withdrawn texts are kept apart', function (string $surface): void {
    $s = revSurface(revWorld(), $surface, 'v1');
    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'v2'])->assertOk();

    $this->actingAs($s['actor'])->deleteJson($s['url'])->assertOk();

    expect(revState($s['table'], $s['message']->ulid))->toMatchArray(['state' => 'deleted', 'body' => null, 'original' => 'v1', 'deleted_body' => 'v2']);
})->with(revSurfaceDataset());

it('deleted --edit--> refused and deleted --delete--> refused, whatever the clock and the thread', function (string $surface): void {
    $w = revWorld();
    $s = revSurface($w, $surface, 'v1');
    $this->actingAs($s['actor'])->deleteJson($s['url'])->assertOk();
    $after = revState($s['table'], $s['message']->ulid);

    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'v2'])->assertUnprocessable()->assertJsonPath('errors.0.code', 'message.already_deleted');
    $this->actingAs($s['actor'])->deleteJson($s['url'])->assertUnprocessable()->assertJsonPath('errors.0.code', 'message.already_deleted');
    // Even with the window long closed and the thread closed, the answer is still already_deleted.
    Carbon::setTestNow(now()->addDay());
    $w['assignment']->forceFill(['status' => AssignmentStatus::Cancelled])->save();
    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'v2'])->assertUnprocessable()->assertJsonPath('errors.0.code', 'message.already_deleted');

    expect(revState($s['table'], $s['message']->ulid))->toBe($after);
})->with(revSurfaceDataset());

it('sent --edit (window closed)--> refused, state unchanged, delete still allowed afterwards', function (string $surface): void {
    $s = revSurface(revWorld(), $surface, 'v1');
    Carbon::setTestNow(now()->addMinutes(16));

    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'v2'])->assertUnprocessable()->assertJsonPath('errors.0.code', 'message.edit_window_closed');
    expect(revState($s['table'], $s['message']->ulid))->toMatchArray(['state' => 'sent', 'body' => 'v1', 'original' => null]);

    $this->actingAs($s['actor'])->deleteJson($s['url'])->assertOk();
    expect(revState($s['table'], $s['message']->ulid)['state'])->toBe('deleted');
})->with(revSurfaceDataset());

it('edited --edit (thread closed)--> refused, state unchanged; delete in the closed thread allowed', function (string $surface): void {
    $w = revWorld();
    $s = revSurface($w, $surface, 'v1');
    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'v2'])->assertOk();
    $w['assignment']->forceFill(['status' => AssignmentStatus::Cancelled])->save();
    DB::table('agency_creator_relations')->where('agency_id', $w['agency']->id)->update(['relationship_status' => 'ended']);

    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'v3'])->assertUnprocessable()->assertJsonPath('errors.0.code', 'message.edit_thread_closed');
    expect(revState($s['table'], $s['message']->ulid))->toMatchArray(['state' => 'edited', 'body' => 'v2', 'original' => 'v1']);

    $this->actingAs($s['actor'])->deleteJson($s['url'])->assertOk();
    expect(revState($s['table'], $s['message']->ulid))->toMatchArray(['state' => 'deleted', 'deleted_body' => 'v2']);
})->with(revSurfaceDataset());
