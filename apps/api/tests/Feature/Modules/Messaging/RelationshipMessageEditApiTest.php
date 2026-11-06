<?php

declare(strict_types=1);

use App\Modules\Creators\Enums\ApplicationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/MessageRevisionFixtures.php';

/*
 * AH-119 B2 — what is specific to the DIRECT (relationship) thread: the open-thread rule for edit is the
 * `canMessageRelationship` gate that governs sending, evaluated for the editing user.
 */

it('refuses an edit once the relationship has ended, on both sides, and still reads the message', function (string $surface): void {
    $w = revWorld();
    $s = revSurface($w, $surface);
    DB::table('agency_creator_relations')->where('agency_id', $w['agency']->id)->update(['relationship_status' => 'ended']);

    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'nope'])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'message.edit_thread_closed');
})->with(['agency-direct' => ['agency-direct'], 'creator-direct' => ['creator-direct']]);

it('refuses an edit once the creator is no longer approved', function (): void {
    $w = revWorld();
    $s = revSurface($w, 'creator-direct');
    DB::table('creators')->where('id', $w['creator']->id)->update(['application_status' => ApplicationStatus::Pending->value]);

    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'nope'])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'message.edit_thread_closed');
});

it('lets any active agency member edit THEIR OWN message, and nobody else\'s', function (): void {
    $w = revWorld();
    $mine = revDirectMessage($w['direct'], $w['staff'], false, 'staff wrote this');
    $url = revUrls($w, $mine->ulid)['agency-direct'];

    $this->actingAs($w['manager'])->patchJson($url, ['body' => 'manager edits it'])->assertNotFound();
    $this->actingAs($w['staff'])->patchJson($url, ['body' => 'staff edits it'])->assertOk();

    expect($mine->refresh()->body)->toBe('staff edits it');
});

it('does not provision a thread: an edit against a pair with no thread is 404', function (): void {
    $w = revWorld();
    $w['direct']->forceDelete();

    $this->actingAs($w['admin'])->patchJson(revUrls($w, '01JNOPENOPENOPENOPENOPENOP')['agency-direct'], ['body' => 'x'])->assertNotFound();

    expect(DB::table('relationship_threads')->count())->toBe(0);
});
