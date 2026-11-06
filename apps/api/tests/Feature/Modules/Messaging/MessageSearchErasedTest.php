<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/MessageRevisionFixtures.php';

/*
 * AH-119 B3 / Q5 — an erased creator's messages stay in the agency's history and stay searchable; the
 * row carries `sender_is_erased` so the UI shows the placeholder, and the creator's name is masked.
 */

it('keeps an erased creator\'s messages findable and flags the sender as erased', function (): void {
    $w = revWorld();
    $mine = revCampaignMessage($w['thread'], $w['creatorUser'], true, 'erasedword from creator');
    $agencyOwn = revCampaignMessage($w['thread'], $w['admin'], false, 'erasedword from agency');
    DB::table('creators')->where('id', $w['creator']->id)->update(['erased_at' => now(), 'display_name' => null]); // what the engine leaves behind

    /** @var list<array<string, mixed>> $data */
    $data = revSearch($w, 'erasedword')->assertOk()->json('data');
    $rows = collect($data)->keyBy('attributes.message_ulid');

    expect($rows)->toHaveCount(2)
        ->and(data_get($rows[$mine->ulid], 'attributes.sender_is_erased'))->toBeTrue()
        ->and(data_get($rows[$agencyOwn->ulid], 'attributes.sender_is_erased'))->toBeFalse()
        ->and(data_get($rows[$mine->ulid], 'attributes.creator'))->toMatchArray(['is_erased' => true, 'display_name' => null])
        ->and(json_encode($rows->all()))->not->toContain('Ana Creator');
});

it('flags nothing for a live creator', function (): void {
    $w = revWorld();
    revCampaignMessage($w['thread'], $w['creatorUser'], true, 'liveword text');

    expect(revSearch($w, 'liveword')->json('data.0.attributes.sender_is_erased'))->toBeFalse();
});
