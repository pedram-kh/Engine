<?php

declare(strict_types=1);

use App\Modules\Erasure\Files\FileKeyCollector;
use App\Modules\Erasure\Files\FileKeys;
use App\Modules\Erasure\Files\FileStep;
use App\Modules\Erasure\Files\MediaDiskObjectStore;
use App\Modules\Erasure\Files\ObjectDeletionFailed;
use App\Modules\Erasure\Files\ObjectStore;
use App\Modules\Erasure\Files\S3VersionPurger;
use App\Modules\Erasure\Subject\SubjectSet;
use Aws\History;
use Aws\Middleware;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ErasureGraph;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** An ObjectStore that records every call and can be told to fail. */
final class RecordingObjectStore implements ObjectStore
{
    /** @var list<string> */
    public array $deleted = [];

    /** @var list<string> */
    public array $listed = [];

    public ?int $failOnDelete = null;

    public function __construct(private readonly ObjectStore $inner) {}

    public function list(string $prefix): array
    {
        $this->listed[] = $prefix;

        return $this->inner->list($prefix);
    }

    public function delete(string $key): void
    {
        if ($this->failOnDelete !== null && count($this->deleted) + 1 === $this->failOnDelete) {
            throw ObjectDeletionFailed::forObject();
        }
        $this->deleted[] = $key;
        $this->inner->delete($key);
    }
}

/** @return array{0: ErasureGraph, 1: SubjectSet, 2: RecordingObjectStore} */
function erasureFilesFixture(string $tag = 'a'): array
{
    Storage::fake('media');
    $graph = ErasureGraph::seedSubject($tag);
    $graph->putFiles();
    $store = new RecordingObjectStore(new MediaDiskObjectStore);

    return [$graph, SubjectSet::resolve($graph->user), $store];
}

it('collects exactly the objects the PII map says go, and names none of the ones that stay', function (): void {
    [$graph, $subject, $store] = erasureFilesFixture();
    ErasureGraph::seedSubject('b')->putFiles();

    $keys = (new FileKeyCollector($store))->collect($subject);

    expect($keys->keys)->toBe($graph->files['delete'])
        ->and($keys->skipped)->toBe(0)
        ->and($keys->byClass)->toBe([
            'avatar' => 1, 'cover' => 1, 'portfolio' => 2, 'portfolio_thumbnail' => 1,
            'unreferenced' => 1, 'message_attachment' => 1, 'relationship_attachment' => 1,
        ]);
});

it('deletes the subject\'s objects and leaves drafts, the agency\'s attachment in the same prefix and the bystander alone', function (): void {
    [$graph, $subject, $store] = erasureFilesFixture();
    $other = ErasureGraph::seedSubject('b');
    $other->putFiles();

    $deleted = (new FileStep($store))->run((new FileKeyCollector($store))->collect($subject));

    $gone = array_values(array_filter($graph->files['delete'], fn (string $k): bool => Storage::disk('media')->exists($k)));
    $stayed = array_values(array_filter([...$graph->files['keep'], ...$other->files['delete'], ...$other->files['keep']], fn (string $k): bool => ! Storage::disk('media')->exists($k)));

    expect($deleted)->toBe(count($graph->files['delete']))
        ->and($gone)->toBe([])
        ->and($stayed)->toBe([]);
});

it('never deletes by prefix: only named keys, and only the two creator-exclusive prefixes are ever listed', function (): void {
    [$graph, $subject, $store] = erasureFilesFixture();
    (new FileStep($store))->run((new FileKeyCollector($store))->collect($subject));

    $own = "creators/{$subject->creatorUlid}/";

    expect($store->listed)->toBe(["{$own}avatar/", "{$own}portfolio/"])
        ->and($store->deleted)->toBe($graph->files['delete']);
});

it('skips, counts and keeps a key the database names outside the subject\'s own prefix', function (string $badKey): void {
    [$graph, $subject, $store] = erasureFilesFixture();
    Storage::disk('media')->put($badKey, 'someone else');
    DB::table('creator_portfolio_items')->where('creator_id', $graph->creator->id)->whereNull('deleted_at')->update(['s3_path' => $badKey]);
    $subject = SubjectSet::resolve($graph->user);

    $keys = (new FileKeyCollector($store))->collect($subject);
    (new FileStep($store))->run($keys);

    expect($keys->skipped)->toBe(1)
        ->and($keys->keys)->not->toContain($badKey)
        ->and(Storage::disk('media')->exists($badKey))->toBeTrue();
})->with([
    'another creator' => ['creators/01JOTHEROTHEROTHEROTHERXX/portfolio/x.jpg'],
    'another module' => ['agencies/1/files/contract.pdf'],
    'a parent-directory escape' => fn () => 'creators/../agencies/1/files/contract.pdf',
]);

it('rejects a parent-directory escape even when it starts with the right prefix', function (): void {
    [$graph, $subject, $store] = erasureFilesFixture();
    $escape = "creators/{$subject->creatorUlid}/portfolio/../../agencies/1/files/contract.pdf";
    DB::table('creator_portfolio_items')->where('creator_id', $graph->creator->id)->whereNull('deleted_at')->update(['s3_path' => $escape]);

    $keys = (new FileKeyCollector($store))->collect(SubjectSet::resolve($graph->user));

    expect($keys->skipped)->toBe(1)
        ->and($keys->keys)->not->toContain($escape);
});

it('only trusts a message attachment under the prefix of the thread the message is in', function (): void {
    [$graph, $subject, $store] = erasureFilesFixture();
    $foreign = 'messages/01JOTHERTHREADOTHERTHREADXX/stolen.pdf';
    DB::table('messages')->where('sender_user_id', $graph->user->id)->whereNotNull('attachments')->update(['attachments' => json_encode([['s3_path' => $foreign]], JSON_THROW_ON_ERROR)]);
    Storage::disk('media')->put($foreign, 'another thread');

    $keys = (new FileKeyCollector($store))->collect($subject);

    expect($keys->skipped)->toBe(1)
        ->and($keys->keys)->not->toContain($foreign);
});

it('changes its digest when the set of objects changes between dry-run and execute', function (): void {
    [$graph, $subject, $store] = erasureFilesFixture();
    $collector = new FileKeyCollector($store);
    $first = $collector->collect($subject);

    expect($collector->collect($subject)->digest())->toBe($first->digest());

    Storage::disk('media')->put("creators/{$subject->creatorUlid}/avatar/late-upload.jpg", 'new');

    expect($collector->collect($subject)->digest())->not->toBe($first->digest());
});

it('prints counts only: the summary and the digest carry no key and no ulid', function (): void {
    [$graph, $subject, $store] = erasureFilesFixture();
    $keys = (new FileKeyCollector($store))->collect($subject);

    $printed = json_encode([$keys->summary(), $keys->digest()], JSON_THROW_ON_ERROR);

    expect($printed)->not->toContain('creators/')
        ->and($printed)->not->toContain($subject->creatorUlid)
        ->and($printed)->not->toContain('messages/');
});

it('stops at the first failure and is resumable: nothing in the database moved, a second run finishes the rest', function (): void {
    [$graph, $subject, $store] = erasureFilesFixture();
    $before = DB::table('creators')->where('id', $graph->creator->id)->first();
    $keys = (new FileKeyCollector($store))->collect($subject);

    $store->failOnDelete = 3;
    $run = fn () => (new FileStep($store))->run($keys);
    expect($run)->toThrow(ObjectDeletionFailed::class);
    $left = array_values(array_filter($keys->keys, fn (string $k): bool => Storage::disk('media')->exists($k)));

    $store->failOnDelete = null;
    $second = (new FileStep($store))->run((new FileKeyCollector($store))->collect($subject));

    expect($left)->toHaveCount(count($keys->keys) - 2)
        ->and(DB::table('creators')->where('id', $graph->creator->id)->first())->toEqual($before)
        ->and($second)->toBeGreaterThanOrEqual(count($left)) // keys a row still names are deleted again: already gone counts as done
        ->and(array_filter($keys->keys, fn (string $k): bool => Storage::disk('media')->exists($k)))->toBe([]);
});

it('treats a key that is already gone as deleted', function (): void {
    Storage::fake('media');
    $store = new MediaDiskObjectStore;

    $store->delete('creators/x/avatar/never-existed.jpg');

    expect(true)->toBeTrue();
});

it('lists nothing and deletes nothing for a creator with no files', function (): void {
    Storage::fake('media');
    $graph = ErasureGraph::seedSubject('a');
    DB::table('creators')->where('id', $graph->creator->id)->update(['avatar_path' => null, 'cover_path' => null]);
    DB::table('creator_portfolio_items')->where('creator_id', $graph->creator->id)->delete();
    DB::table('messages')->update(['attachments' => null]);
    DB::table('relationship_messages')->update(['attachments' => null]);

    $keys = (new FileKeyCollector(new MediaDiskObjectStore))->collect(SubjectSet::resolve($graph->user));

    expect($keys->keys)->toBe([])
        ->and($keys->skipped)->toBe(0)
        ->and((new FileStep(new MediaDiskObjectStore))->run($keys))->toBe(0);
});

it('purges every version and delete marker of the exact key, across pages, and no other key', function (): void {
    $mock = new MockHandler;
    $mock->append(
        new Result(['Versions' => [['Key' => 'a/b.jpg', 'VersionId' => 'v1'], ['Key' => 'a/b.jpg.bak', 'VersionId' => 'other']], 'DeleteMarkers' => [['Key' => 'a/b.jpg', 'VersionId' => 'm1']], 'IsTruncated' => true, 'NextKeyMarker' => 'a/b.jpg', 'NextVersionIdMarker' => 'v1']),
        new Result([]), new Result([]),
        new Result(['Versions' => [['Key' => 'a/b.jpg', 'VersionId' => 'v2'], ['Key' => 'a/b.jpgx', 'VersionId' => 'other2']], 'IsTruncated' => false]),
        new Result([]),
    );
    $client = new S3Client(['region' => 'us-east-1', 'version' => 'latest', 'credentials' => ['key' => 'k', 'secret' => 's'], 'handler' => $mock]);
    $history = new History;
    $client->getHandlerList()->appendSign(Middleware::history($history));

    $purged = (new S3VersionPurger($client, 'bucket'))->purge('a/b.jpg');

    $deletes = [];
    foreach ($history->toArray() as $entry) {
        if ($entry['command']->getName() === 'DeleteObject') {
            $deletes[] = $entry['command']['Key'].'@'.$entry['command']['VersionId'];
        }
    }

    expect($purged)->toBe(3)
        ->and($deletes)->toBe(['a/b.jpg@v1', 'a/b.jpg@m1', 'a/b.jpg@v2']);
});

it('keeps FileKeys a plain value: sorted unique keys, class counts, a skipped count', function (): void {
    $keys = new FileKeys(['a', 'b'], ['avatar' => 2], 1);

    expect($keys->count())->toBe(2)
        ->and($keys->summary())->toBe(['avatar' => 2, 'skipped_outside_own_prefix' => 1]);
});
