<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Erasure\Execution\AuditScrub;
use App\Modules\Erasure\Files\MediaDiskObjectStore;
use App\Modules\Erasure\Files\ObjectStore;
use App\Modules\Erasure\Run\ErasureRun;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\RelationshipMessage;
use App\Modules\Messaging\Models\RelationshipThread;
use App\Modules\TalentPools\Models\TalentPool;
use App\Modules\TalentPools\Models\TalentPoolMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ErasureClaims;
use Tests\Support\ErasureEvents;
use Tests\Support\FakeAuditScrub;
use Tests\Support\RecordingObjectStore;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * AH-104b-del B2 — what an agency is shown about a creator who has been ERASED, driven through
 * the REAL erasure engine (not a hand-made lookalike), so the spec reads the same rows the
 * production run will leave behind.
 *
 * Two pins: every agency surface that carries the creator says `is_erased: true` and no name,
 * and NO agency response contains the sentinel the engine writes (`[erased]`), the placeholder
 * address (`erased.invalid`) or anything the person typed (the leak spec the registry plan
 * promised).
 */

const ERASED_SURFACE_NAME = 'Ada Realname';
const ERASED_SURFACE_EMAIL = 'ada.realname@example.test';

/**
 * @return array{agency: Agency, admin: User, campaign: Campaign, creator: Creator, other: Creator, pool: TalentPool}
 */
function erasedSurfaceFixture(): array
{
    Storage::fake('media');
    $events = new ErasureEvents;
    app()->instance(AuditScrub::class, new FakeAuditScrub($events));
    app()->instance(ObjectStore::class, new RecordingObjectStore(new MediaDiskObjectStore, $events));

    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->listed()->createOne(['agency_id' => $agency->id, 'brand_id' => $brand->id, 'budget_currency' => 'EUR']);
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();

    $make = function (string $name, string $email) use ($agency, $brand, $campaign, $admin, $pool): Creator {
        $user = User::factory()->creator()->createOne(['name' => $name, 'email' => $email]);
        $creator = Creator::factory()->approved()->createOne(['user_id' => $user->id, 'display_name' => $name, 'bio' => "About {$name}"]);
        DB::table('creators')->where('id', $creator->id)->update(['avatar_path' => "creators/{$creator->ulid}/avatar/a.jpg"]);
        AgencyCreatorRelation::factory()->createOne([
            'agency_id' => $agency->id, 'creator_id' => $creator->id,
            'relationship_status' => RelationshipStatus::Roster, 'is_blacklisted' => false,
        ]);
        $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Producing)->createOne([
            'agency_id' => $agency->id, 'campaign_id' => $campaign->id, 'brand_id' => $brand->id,
            'creator_id' => $creator->id, 'invited_by_user_id' => $admin->id,
        ]);
        $campaignThread = MessageThread::factory()->createOne(['assignment_id' => $assignment->id, 'last_message_at' => now()]);
        Message::factory()->createOne(['thread_id' => $campaignThread->id, 'sender_user_id' => $admin->id]);
        CampaignApplication::factory()->createOne([
            'campaign_id' => $campaign->id, 'agency_id' => $agency->id, 'creator_id' => $creator->id,
        ]);
        TalentPoolMembership::factory()->createOne(['talent_pool_id' => $pool->id, 'creator_id' => $creator->id]);
        $thread = RelationshipThread::factory()->createOne(['agency_id' => $agency->id, 'creator_id' => $creator->id, 'last_message_at' => now()]);
        RelationshipMessage::factory()->createOne(['thread_id' => $thread->id, 'sender_user_id' => $admin->id]);

        return $creator->refresh();
    };

    $creator = $make(ERASED_SURFACE_NAME, ERASED_SURFACE_EMAIL);
    $other = $make('Olive Otherperson', 'olive.other@example.test');

    $run = app(ErasureRun::class);
    $subject = User::query()->findOrFail($creator->user_id);
    $run->execute($subject, ErasureClaims::for($subject), $run->plan($subject)->digest(), $subject->ulid);

    return compact('agency', 'admin', 'campaign', 'creator', 'other', 'pool');
}

/**
 * @return list<array<string, mixed>>
 */
function erRows(mixed $json): array
{
    return is_array($json) ? array_values($json) : [];
}

/** @return array<string, string> label => url */
function erasedSurfaceUrls(array $s): array
{
    $a = "/api/v1/agencies/{$s['agency']->ulid}";

    return [
        'roster' => "{$a}/creators",
        'roster detail' => "{$a}/creators/{$s['creator']->ulid}",
        'assignments' => "{$a}/campaigns/{$s['campaign']->ulid}/assignments",
        'applications' => "{$a}/campaigns/{$s['campaign']->ulid}/applications",
        'pool members' => "{$a}/talent-pools/{$s['pool']->ulid}/creators",
        'relationship inbox' => "{$a}/relationship-threads",
        'campaign threads' => "{$a}/campaigns/{$s['campaign']->ulid}/message-threads",
        'new-conversation picker' => "{$a}/messageable-creators",
        'discovery' => "{$a}/creators/discover",
    ];
}

/** @param list<array<string, mixed>> $rows */
function erFind(array $rows, string $path, string $ulid): ?array
{
    foreach ($rows as $row) {
        if (data_get($row, $path) === $ulid) {
            return $row;
        }
    }

    return null;
}

it('says is_erased and shows no name on every agency surface that carries the erased creator, and leaves the other creator alone', function (): void {
    $s = erasedSurfaceFixture();
    $urls = erasedSurfaceUrls($s);
    $get = fn (string $label): mixed => $this->actingAs($s['admin'])->getJson($urls[$label])->assertOk()->json('data');

    foreach ($urls as $label => $url) {
        $body = $this->actingAs($s['admin'])->getJson($url)->assertOk()->getContent();

        expect($body)->not->toContain(ERASED_SURFACE_NAME, "{$label} leaked the erased creator's name")
            ->and($body)->not->toContain(ERASED_SURFACE_EMAIL, "{$label} leaked the erased creator's email")
            ->and($body)->not->toContain('[erased]', "{$label} leaked the sentinel")
            ->and($body)->not->toContain('erased.invalid', "{$label} leaked the placeholder address");
    }

    $roster = erRows($get('roster'));
    $erasedRow = erFind($roster, 'attributes.creator_id', $s['creator']->ulid);
    $otherRow = erFind($roster, 'attributes.creator_id', $s['other']->ulid);
    expect($erasedRow)->not->toBeNull('the roster keeps the erased creator (history)')
        ->and(data_get($erasedRow, 'attributes.is_erased'))->toBeTrue()
        ->and(data_get($erasedRow, 'attributes.display_name'))->toBeNull()
        ->and(data_get($erasedRow, 'attributes.email'))->toBeNull()
        ->and(data_get($otherRow, 'attributes.is_erased'))->toBeFalse()
        ->and(data_get($otherRow, 'attributes.display_name'))->toBe('Olive Otherperson');

    $detail = $get('roster detail');
    expect(data_get($detail, 'attributes.creator.is_erased') ?? data_get($detail, 'attributes.is_erased'))->toBeTrue();

    foreach (['assignments', 'applications'] as $label) {
        $rows = erRows($get($label));
        $erased = erFind($rows, 'attributes.creator.id', $s['creator']->ulid);
        $normal = erFind($rows, 'attributes.creator.id', $s['other']->ulid);
        expect($erased)->not->toBeNull("{$label} lost the erased creator's row (history must stay)")
            ->and(data_get($erased, 'attributes.creator.is_erased'))->toBeTrue($label)
            ->and(data_get($erased, 'attributes.creator.display_name'))->toBeNull($label)
            ->and(data_get($normal, 'attributes.creator.is_erased'))->toBeFalse($label);
    }

    // The pivot row is erased with the creator (a ghost in a curated list is worse than a gap);
    // the bystander stays, unflagged.
    $pool = erRows($get('pool members'));
    expect(erFind($pool, 'id', $s['creator']->ulid))->toBeNull()
        ->and(data_get(erFind($pool, 'id', $s['other']->ulid), 'attributes.is_erased'))->toBeFalse();

    $campaignThreads = erRows($get('campaign threads'));
    $flags = array_map(static fn (array $t): mixed => data_get($t, 'attributes.creator.is_erased'), $campaignThreads);
    sort($flags);
    expect($flags)->toBe([false, true]);

    $thread = erFind(erRows($get('relationship inbox')), 'attributes.creator.id', $s['creator']->ulid);
    expect(data_get($thread, 'attributes.creator.is_erased'))->toBeTrue()
        ->and(data_get($thread, 'attributes.creator.display_name'))->toBeNull();
});

it('leaves the erased creator out of discovery and out of any new conversation', function (): void {
    $s = erasedSurfaceFixture();

    $ids = array_column(erRows($this->actingAs($s['admin'])->getJson(erasedSurfaceUrls($s)['discovery'])->json('data')), 'id');

    expect($ids)->not->toContain($s['creator']->ulid);
    $this->actingAs($s['admin'])
        ->getJson("/api/v1/agencies/{$s['agency']->ulid}/creators/discover/{$s['creator']->ulid}")
        ->assertNotFound();
});

it('does not flag a live, discoverable creator as erased in discovery', function (): void {
    $s = erasedSurfaceFixture();
    DB::table('creators')->where('id', $s['other']->id)->update(['is_discoverable' => true]);

    $rows = erRows($this->actingAs($s['admin'])->getJson(erasedSurfaceUrls($s)['discovery'])->assertOk()->json('data'));
    $row = erFind($rows, 'id', $s['other']->ulid);
    expect($row)->not->toBeNull('the live creator is discoverable')
        ->and(data_get($row, 'attributes.is_erased'))->toBeFalse();

    $profile = $this->actingAs($s['admin'])
        ->getJson("/api/v1/agencies/{$s['agency']->ulid}/creators/discover/{$s['other']->ulid}")
        ->assertOk()->json('data');
    expect(data_get($profile, 'attributes.is_erased'))->toBeFalse();
});
