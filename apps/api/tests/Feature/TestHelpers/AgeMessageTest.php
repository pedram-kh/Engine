<?php

declare(strict_types=1);

use App\TestHelpers\Http\Middleware\VerifyTestHelperToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Message-age test-helper (AH-119 B2)
|--------------------------------------------------------------------------
|
| POST /_test/messages/{ulid}/age {minutes} moves one message's created_at back, so a browser spec
| can close the 15-minute edit window without waiting. The header-missing and wrong-token cases are
| in `GatingTest.php`; this file proves the effect, the validation, and the gate-closed 404.
*/

uses(TestCase::class);
uses(RefreshDatabase::class);

require_once dirname(__DIR__).'/../Support/MessageRevisionFixtures.php';

beforeEach(function (): void {
    $this->withHeader(VerifyTestHelperToken::HEADER, (string) config('test_helpers.token'));
});

it('ages a campaign message and a direct message, and ONLY created_at moves', function (string $surface): void {
    $w = revWorld();
    $s = revSurface($w, $surface);
    $before = revRow($s['table'], $s['message']->ulid);

    $this->postJson("/api/v1/_test/messages/{$s['message']->ulid}/age", ['minutes' => 16])
        ->assertOk()
        ->assertJsonPath('data.id', $s['message']->ulid)
        ->assertJsonPath('data.table', $s['table']);

    $after = revRow($s['table'], $s['message']->ulid);
    expect(Carbon::parse($after->created_at)->diffInMinutes(Carbon::parse($before->created_at), false))->toBe(16.0)
        ->and($after->updated_at)->toBe($before->updated_at)
        ->and($after->body)->toBe($before->body)
        ->and($after->deleted_at)->toBe($before->deleted_at);
})->with(['campaign' => ['agency-campaign'], 'direct' => ['agency-direct']]);

it('is what actually closes the edit window through the public API', function (): void {
    $w = revWorld();
    $s = revSurface($w, 'agency-direct');
    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'in time'])->assertOk();

    $this->postJson("/api/v1/_test/messages/{$s['message']->ulid}/age", ['minutes' => 16])->assertOk();

    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'too late'])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'message.edit_window_closed');
});

it('can age a deleted message too', function (): void {
    $w = revWorld();
    $s = revSurface($w, 'agency-campaign');
    $this->actingAs($s['actor'])->deleteJson($s['url'])->assertOk();

    $this->postJson("/api/v1/_test/messages/{$s['message']->ulid}/age", ['minutes' => 5])->assertOk();
});

it('404s an unknown ulid and 422s a bad minutes value', function (mixed $minutes): void {
    $w = revWorld();
    $s = revSurface($w, 'agency-direct');

    $this->postJson('/api/v1/_test/messages/01JNOPENOPENOPENOPENOPENOP/age', ['minutes' => 16])->assertNotFound();
    $this->postJson("/api/v1/_test/messages/{$s['message']->ulid}/age", ['minutes' => $minutes])->assertUnprocessable();
})->with([0, -3, 10081, '16', 1.5, null]);

it('leaves the thread ordering alone', function (): void {
    $w = revWorld();
    $s = revSurface($w, 'agency-direct');
    $thread = DB::table('relationship_threads')->where('id', $w['direct']->id)->value('last_message_at');

    $this->postJson("/api/v1/_test/messages/{$s['message']->ulid}/age", ['minutes' => 60])->assertOk();

    expect(DB::table('relationship_threads')->where('id', $w['direct']->id)->value('last_message_at'))->toBe($thread);
});

it('is a 404 when the gate is closed, and never touches the row', function (): void {
    $w = revWorld();
    $s = revSurface($w, 'agency-direct');
    $before = revRow($s['table'], $s['message']->ulid)->created_at;
    config()->set('test_helpers.token', '');

    $this->postJson("/api/v1/_test/messages/{$s['message']->ulid}/age", ['minutes' => 16])->assertNotFound();

    expect(revRow($s['table'], $s['message']->ulid)->created_at)->toBe($before);
});
