<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/MessageInboxFixtures.php';

/** AH-119 A2 — the negatives: another agency's threads, brands, campaigns and creators are invisible. */
it('never lists another agency\'s threads, and counts nothing of theirs', function (): void {
    ['agency' => $a, 'admin' => $adminA] = inboxWorld();
    ['agency' => $b] = inboxWorld();
    $mine = inboxAssignment($a);
    inboxDirect($b, inboxCreator('Theirs'));
    inboxAssignment($b, humans: 5);

    $res = $this->actingAs($adminA)->getJson(inboxUrl($a))->assertOk();

    expect(inboxList($res, 'data')->pluck('id')->all())->toBe([$mine['thread']->ulid])
        ->and($res->json('meta.unread_threads_total'))->toBe(1)
        ->and($res->json('meta.facets.brands'))->toHaveCount(1);
});

it('IGNORES a brand or campaign ulid from another agency: no 404, no leak, no filtering by it', function (): void {
    ['agency' => $a, 'admin' => $adminA] = inboxWorld();
    ['agency' => $b] = inboxWorld();
    $mine = inboxAssignment($a);
    $theirs = inboxAssignment($b);

    $foreignBrand = $this->actingAs($adminA)->getJson(inboxUrl($a, 'brand[]='.$theirs['brand']->ulid))->assertOk();
    $foreignCampaign = $this->actingAs($adminA)->getJson(inboxUrl($a, 'campaign[]='.$theirs['campaign']->ulid))->assertOk();
    $unknown = $this->actingAs($adminA)->getJson(inboxUrl($a, 'brand[]=01HZZZZZZZZZZZZZZZZZZZZZZZ'))->assertOk();

    foreach ([$foreignBrand, $foreignCampaign, $unknown] as $res) {
        expect(inboxList($res, 'data')->pluck('id')->all())->toBe([$mine['thread']->ulid]);
    }
});

it('404s for a user who is not a member of the agency', function (): void {
    ['agency' => $a] = inboxWorld();
    ['admin' => $outsider] = inboxWorld();

    $this->actingAs($outsider)->getJson(inboxUrl($a))->assertNotFound();
});

it('401s unauthenticated', function (): void {
    ['agency' => $a] = inboxWorld();

    $this->getJson(inboxUrl($a))->assertUnauthorized();
});

it('does not show another agency\'s member as an owner, and a platform-admin inviter reads as unowned', function (): void {
    ['agency' => $a, 'admin' => $adminA] = inboxWorld();
    $outsider = User::factory()->createOne(['name' => 'Platform Person']);
    $creator = inboxCreator();
    inboxRelation($a, $creator, inviter: $outsider);
    inboxDirect($a, $creator);

    $row = $this->actingAs($adminA)->getJson(inboxUrl($a))->json('data.0.attributes');
    expect($row['owner'])->toBeNull();
});
