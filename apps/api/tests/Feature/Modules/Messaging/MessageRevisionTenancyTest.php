<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Exceptions\MessageRevisionException;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Services\MessageRevisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/MessageRevisionFixtures.php';

/*
 * AH-119 B2 — the §5.34 over-reach negatives. Every one answers 404 (never 403, never a hint) and
 * leaves the row byte-identical.
 */

function revAssertUntouched(string $table, string $ulid, string $body = 'original text'): void
{
    $row = revRow($table, $ulid);
    expect($row->body)->toBe($body)->and($row->edited_at)->toBeNull()->and($row->deleted_at)->toBeNull()->and($row->original_body)->toBeNull()->and($row->deleted_body)->toBeNull();
}

it('404s another agency\'s admin, on a foreign message ulid and on the victim\'s own path', function (string $surface): void {
    $w = revWorld();
    $s = revSurface($w, $surface);
    $strangerAgency = Agency::factory()->createOne();
    $stranger = User::factory()->agencyAdmin($strangerAgency)->createOne();

    // The victim's agency path: the tenancy stack refuses a non-member.
    foreach (['patch', 'delete'] as $verb) {
        $this->actingAs($stranger)->{$verb.'Json'}(revUrls($w, $s['message']->ulid)['agency-campaign'], ['body' => 'x'])->assertNotFound();
        $this->actingAs($stranger)->{$verb.'Json'}(revUrls($w, $s['message']->ulid)['agency-direct'], ['body' => 'x'])->assertNotFound();
    }

    // The stranger's own agency path with a foreign ulid: the lookup is thread-scoped.
    $other = revWorld();
    $other['agency']->forceFill([])->save();
    $strangerAdmin = $other['admin'];
    foreach (['agency-campaign', 'agency-direct'] as $name) {
        $this->actingAs($strangerAdmin)->patchJson(revUrls($other, $s['message']->ulid)[$name], ['body' => 'x'])->assertNotFound();
        $this->actingAs($strangerAdmin)->deleteJson(revUrls($other, $s['message']->ulid)[$name])->assertNotFound();
    }

    revAssertUntouched($s['table'], $s['message']->ulid);
})->with(revSurfaceDataset());

it('404s a message ulid that belongs to ANOTHER thread, even in the same agency', function (): void {
    $w = revWorld();
    $mine = revCampaignMessage($w['thread'], $w['admin'], false, 'mine');
    $otherWorld = revWorld();
    // Same user would have to be a member of both: build the second thread INSIDE the first agency.
    $foreignThread = MessageThread::factory()->forAssignment(
        CampaignAssignment::factory()->createOne([
            'agency_id' => $w['agency']->id, 'campaign_id' => $w['campaign']->id, 'brand_id' => $w['campaign']->brand_id,
            'creator_id' => CreatorFactory::new()->approved()->createOne()->id,
        ]),
    )->createOne();
    $theirs = revCampaignMessage($foreignThread, $w['admin'], false, 'theirs');

    $this->actingAs($w['admin'])->patchJson(revUrls($w, $theirs->ulid)['agency-campaign'], ['body' => 'x'])->assertNotFound();
    $this->actingAs($w['admin'])->deleteJson(revUrls($w, $theirs->ulid)['agency-campaign'])->assertNotFound();

    // The lookup comes BEFORE validation: a foreign ulid with an invalid body is still a 404, never a 422.
    $this->actingAs($w['admin'])->patchJson(revUrls($w, $theirs->ulid)['agency-campaign'], ['body' => ''])->assertNotFound();

    revAssertUntouched('messages', $theirs->ulid, 'theirs');
    expect($mine->refresh()->body)->toBe('mine')->and($otherWorld)->toBeArray();
});

it('404s another user\'s message: a colleague, and the other party', function (): void {
    $w = revWorld();
    $agencyCampaign = revSurface($w, 'agency-campaign');
    $creatorDirect = revSurface($w, 'creator-direct');

    // A colleague (manager) on the admin's message, the creator on the agency's messages (both kinds).
    $this->actingAs($w['manager'])->patchJson($agencyCampaign['url'], ['body' => 'x'])->assertNotFound();
    $this->actingAs($w['manager'])->deleteJson($agencyCampaign['url'])->assertNotFound();
    $this->actingAs($w['creatorUser'])->patchJson(revUrls($w, $agencyCampaign['message']->ulid)['creator-campaign'], ['body' => 'x'])->assertNotFound();
    $this->actingAs($w['creatorUser'])->deleteJson(revUrls($w, $agencyCampaign['message']->ulid)['creator-campaign'])->assertNotFound();
    // And the agency on the creator's direct message.
    $this->actingAs($w['admin'])->patchJson(revUrls($w, $creatorDirect['message']->ulid)['agency-direct'], ['body' => 'x'])->assertNotFound();
    $this->actingAs($w['admin'])->deleteJson(revUrls($w, $creatorDirect['message']->ulid)['agency-direct'])->assertNotFound();

    revAssertUntouched('messages', $agencyCampaign['message']->ulid);
    revAssertUntouched('relationship_messages', $creatorDirect['message']->ulid);
});

it('404s a creator editing an agency message through the creator URL, and another creator entirely', function (): void {
    $w = revWorld();
    $mine = revSurface($w, 'creator-campaign');
    $otherCreatorUser = User::factory()->createOne();
    CreatorFactory::new()->approved()->createOne(['user_id' => $otherCreatorUser->id]);

    foreach (['creator-campaign', 'creator-direct'] as $name) {
        $this->actingAs($otherCreatorUser)->patchJson(revUrls($w, $mine['message']->ulid)[$name], ['body' => 'x'])->assertNotFound();
        $this->actingAs($otherCreatorUser)->deleteJson(revUrls($w, $mine['message']->ulid)[$name])->assertNotFound();
    }

    revAssertUntouched('messages', $mine['message']->ulid);
});

it('404s a SYSTEM line on both verbs: it has no sender', function (): void {
    $w = revWorld();
    $system = Message::factory()->system('assignment.offer_edited')->createOne(['thread_id' => $w['thread']->id]);
    $url = revUrls($w, $system->ulid)['agency-campaign'];

    $this->actingAs($w['admin'])->patchJson($url, ['body' => 'rewrite history'])->assertNotFound();
    $this->actingAs($w['admin'])->deleteJson($url)->assertNotFound();
    $this->actingAs($w['creatorUser'])->deleteJson(revUrls($w, $system->ulid)['creator-campaign'])->assertNotFound();

    expect($system->refresh()->body)->toBeNull()->and($system->deleted_at)->toBeNull();
});

it('404s an unknown ulid and a non-member agency user', function (): void {
    $w = revWorld();
    $s = revSurface($w, 'agency-campaign');

    $this->actingAs($w['admin'])->patchJson(revUrls($w, '01JNOPENOPENOPENOPENOPENOP')['agency-campaign'], ['body' => 'x'])->assertNotFound();
    $this->actingAs($w['admin'])->deleteJson(revUrls($w, '01JNOPENOPENOPENOPENOPENOP')['agency-direct'])->assertNotFound();
    $this->actingAs(User::factory()->agencyAdmin()->createOne())->deleteJson($s['url'])->assertNotFound();

    revAssertUntouched('messages', $s['message']->ulid);
});

it('answers an anonymous caller 401 on all eight routes (before the flag or tenancy is read)', function (string $surface): void {
    $s = revSurface(revWorld(), $surface);

    $this->patchJson($s['url'], ['body' => 'x'])->assertUnauthorized();
    $this->deleteJson($s['url'])->assertUnauthorized();

    revAssertUntouched($s['table'], $s['message']->ulid);
})->with(revSurfaceDataset());

it('does not tell a foreign message apart from a missing one: same status and error code', function (): void {
    $w = revWorld();
    $theirs = revSurface(revWorld(), 'agency-campaign');

    $foreign = $this->actingAs($w['admin'])->deleteJson(revUrls($w, $theirs['message']->ulid)['agency-campaign']);
    $missing = $this->actingAs($w['admin'])->deleteJson(revUrls($w, '01JNOPENOPENOPENOPENOPENOP')['agency-campaign']);

    expect($foreign->getStatusCode())->toBe($missing->getStatusCode())->toBe(404)
        ->and($foreign->json('errors.0.code'))->toBe($missing->json('errors.0.code'));
});

it('refuses at the SERVICE too: a message that is not in the given thread is not found (second layer behind the controller lookup)', function (): void {
    $w = revWorld();
    $other = revWorld();
    $foreign = revCampaignMessage($other['thread'], $w['admin'], false, 'not in this thread');
    $service = app(MessageRevisionService::class);

    foreach (['edit' => fn () => $service->edit($w['thread'], $foreign, $w['admin'], 'x'), 'delete' => fn () => $service->delete($w['thread'], $foreign, $w['admin'])] as $name => $call) {
        expect($call)->toThrow(MessageRevisionException::class, '');
        revAssertUntouched('messages', $foreign->ulid, 'not in this thread');
    }
});
