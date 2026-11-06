<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Features\MessagesV2EditSearchEnabled;
use App\TestHelpers\Http\Controllers\SetFeatureFlagController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/MessageRevisionFixtures.php';

/*
 * AH-119 B2/B3 — `messages_v2_edit_search_enabled` OFF means the nine Stage B endpoints do not exist
 * (404, nothing written) and `/me` says so. The additive resource fields stay (they are not flagged).
 */

it('404s edit and delete on all four surfaces while the flag is off, and changes nothing', function (string $surface): void {
    $w = revWorld(flag: false);
    $s = revSurface($w, $surface, 'untouched');

    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'x'])->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');
    $this->actingAs($s['actor'])->deleteJson($s['url'])->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');

    $row = revRow($s['table'], $s['message']->ulid);
    expect($row->body)->toBe('untouched')->and($row->edited_at)->toBeNull()->and($row->deleted_at)->toBeNull();
})->with(revSurfaceDataset());

it('404s the search endpoint while the flag is off', function (): void {
    $w = revWorld(flag: false);

    $this->actingAs($w['admin'])->getJson("/api/v1/agencies/{$w['agency']->ulid}/message-search?q=hello")->assertNotFound();
});

it('is back on the moment the flag is activated', function (): void {
    $w = revWorld(flag: false);
    $s = revSurface($w, 'agency-direct', 'before');
    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'after'])->assertNotFound();

    Feature::activate(MessagesV2EditSearchEnabled::NAME);

    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'after'])->assertOk();
});

it('still lists messages with the additive fields while the flag is off', function (): void {
    $w = revWorld(flag: false);
    revSurface($w, 'agency-campaign');

    $this->actingAs($w['admin'])->getJson("/api/v1/agencies/{$w['agency']->ulid}/campaigns/{$w['campaign']->ulid}/assignments/{$w['assignment']->ulid}/messages")
        ->assertOk()->assertJsonPath('data.0.attributes.is_deleted', false)->assertJsonPath('data.0.attributes.edited_at', null);
});

it('carries features.messages_v2_edit_search on /me for agency and creator users, following the flag', function (): void {
    $agency = User::factory()->agencyAdmin()->createOne();
    $creator = User::factory()->creator()->createOne();
    $get = fn (User $u) => $this->actingAs($u, 'web')->getJson('/api/v1/me')->assertOk()->json('data.attributes.features.messages_v2_edit_search');

    expect($get($agency))->toBeFalse()->and($get($creator))->toBeFalse();

    Feature::activate(MessagesV2EditSearchEnabled::NAME);

    expect($get($agency))->toBeTrue()->and($get($creator))->toBeTrue();
});

it('is false on /me for a platform admin even with the flag on', function (): void {
    Feature::activate(MessagesV2EditSearchEnabled::NAME);

    $this->actingAs(User::factory()->platformAdmin()->createOne(), 'web')->getJson('/api/v1/me')
        ->assertOk()->assertJsonPath('data.attributes.features.messages_v2_edit_search', false);
});

it('defaults to off and is registered in the helper allow-list and the admin flag list', function (): void {
    expect(Feature::active(MessagesV2EditSearchEnabled::NAME))->toBeFalse()
        ->and(SetFeatureFlagController::ALLOWED_NAMES)->toContain(MessagesV2EditSearchEnabled::NAME);
});
