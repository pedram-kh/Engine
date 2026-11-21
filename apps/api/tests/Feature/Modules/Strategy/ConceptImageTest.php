<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Console\SweepPendingConceptImages;
use App\Modules\Strategy\Jobs\GenerateConceptImageThumbnail;
use App\Modules\Strategy\Models\WaveConceptImage;
use App\Modules\Strategy\Services\ConceptThumbnailer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ConceptFixtures as C;
use Tests\Support\StrategyFixtures as S;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-2.3 — concept reference images (plan sections 1.5, 1.7, 1.8, rulings C4, Q9, Q12): the reservation at init, the
 * verification at complete (the stored object's own MIME and size plus a header check), reads through the API only, the
 * thumbnail threshold (a decompression-bomb header must not take the worker down), object-first deletes, duplicate with no
 * shared objects, and the sweeper. The reservation LOCK itself is in ConceptImageLockTest (PostgreSQL).
 */

beforeEach(fn () => Storage::fake('media'));

/**
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function conceptImageInit(array $w, string $concept, array $body): TestResponse
{
    return S::call($w['staff'], 'POST', S::u($w, "concepts/{$concept}/images/uploads/init"), $body);
}

/** A PNG whose header says $w x $h and whose body is a few bytes: a decompression bomb in miniature. */
function bombPng(int $w, int $h): string
{
    $ihdr = pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0);
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', $ihdr).$chunk('IDAT', "\x78\x9c\x63\x00\x00\x00\x02\x00\x01").$chunk('IEND', '');
}

it('init reserves a pending row and returns an upload URL and id; the key is under the concept, never a GET URL', function (): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));

    $data = conceptImageInit($w, $id, ['mime_type' => 'image/png', 'declared_bytes' => 1000, 'original_filename' => 'ref.png'])->assertCreated()->json('data');
    $row = WaveConceptImage::query()->firstOrFail();

    expect($data['upload_id'])->toBe($row->storage_key)->and($data['max_bytes'])->toBe(300 * 1024 * 1024)
        ->and($row->storage_key)->toMatch('#^agencies/[0-9A-Z]{26}/brands/[0-9A-Z]{26}/waves/[0-9A-Z]{26}/concepts/[0-9A-Z]{26}/images/[0-9A-Z]{26}\.png$#')
        ->and($row->status)->toBe('pending')->and($row->declared_bytes)->toBe(1000)->and($row->size_bytes)->toBeNull()->and($row->mime_type)->toBeNull()
        ->and($row->expires_at?->isFuture())->toBeTrue();
    // A pending reservation is not an image: the card does not show it.
    expect(S::call($w['admin'], 'GET', S::u($w, 'concepts?market=SK'))->json('data.0.attributes.images'))->toBe([]);
});

it('init refuses a type that is not JPG, PNG or WebP, an empty file and a file over 300 MB', function (): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));
    foreach (['image/gif', 'image/svg+xml', 'application/pdf', 'text/html', ''] as $mime) {
        conceptImageInit($w, $id, ['mime_type' => $mime, 'declared_bytes' => 10, 'original_filename' => 'a'])->assertUnprocessable();
    }
    conceptImageInit($w, $id, ['mime_type' => 'image/png', 'declared_bytes' => 0, 'original_filename' => 'a'])->assertUnprocessable();
    conceptImageInit($w, $id, ['mime_type' => 'image/png', 'declared_bytes' => 300 * 1024 * 1024 + 1, 'original_filename' => 'a'])->assertUnprocessable()->assertJsonPath('errors.0.code', 'concept_image.limit_bytes');
    expect(WaveConceptImage::query()->count())->toBe(0);
});

it('complete: reads the stored object (size and MIME from it, not from the request), marks it ready and makes a thumbnail', function (): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));
    $bytes = C::png(64, 48);

    $res = C::upload($w, $id, $bytes, 'image/png', 'ref.png', declared: strlen($bytes) + 500)->assertCreated();
    $row = WaveConceptImage::query()->firstOrFail();

    expect($row->status)->toBe('ready')->and($row->size_bytes)->toBe(strlen($bytes))->and($row->mime_type)->toBe('image/png')->and($row->expires_at)->toBeNull()
        ->and($res->json('data.size_bytes'))->toBe(strlen($bytes))->and($res->json('data'))->not->toHaveKeys(['storage_key', 'thumb_key']);
    Storage::disk('media')->assertExists($row->storage_key);
    expect($row->thumb_key)->not->toBeNull();
    Storage::disk('media')->assertExists((string) $row->thumb_key);
    expect($res->json('data.has_thumbnail'))->toBeTrue();
    $audit = C::lastAudit('wave_concept_image.added');
    expect($audit)->toMatchArray(['mime_type' => 'image/png', 'size_bytes' => strlen($bytes)])->and(array_keys($audit))->not->toContain('original_filename');
});

it('Q12: complete refuses what is not the declared image and deletes the object and the reservation', function (string $case): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));
    [$mime, $name, $bytes, $declared] = match ($case) {
        'text with an image name' => ['image/png', 'x.png', 'this is not an image at all', 27],
        'jpeg declared png' => ['image/png', 'x.png', C::jpeg(), 5000],
        'png declared webp' => ['image/webp', 'x.webp', C::png(), 5000],
        'bigger than reserved' => ['image/png', 'x.png', C::png(200, 200), 100],
        'html with a png name' => ['image/png', 'x.png', '<html><script>alert(1)</script></html>', 100],
        'gif bytes' => ['image/png', 'x.png', (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true), 100],
        default => throw new LogicException('unknown case'),
    };

    $res = C::upload($w, $id, $bytes, $mime, $name, declared: $declared);
    $res->assertUnprocessable()->assertJsonPath('errors.0.code', 'concept_image.invalid');
    expect(WaveConceptImage::query()->count())->toBe(0)->and(Storage::disk('media')->allFiles())->toBe([]);
})->with(['text with an image name', 'jpeg declared png', 'png declared webp', 'bigger than reserved', 'html with a png name', 'gif bytes']);

it('complete: no object at the key, an unknown id, a second completion and an expired reservation are refused', function (): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));
    $complete = fn (string $uploadId) => S::call($w['staff'], 'POST', S::u($w, "concepts/{$id}/images/uploads/complete"), ['upload_id' => $uploadId]);

    $init = conceptImageInit($w, $id, ['mime_type' => 'image/png', 'declared_bytes' => 100000, 'original_filename' => 'a.png'])->assertCreated()->json('data.upload_id');
    $complete($init)->assertUnprocessable()->assertJsonPath('errors.0.code', 'concept_image.invalid');
    expect(WaveConceptImage::query()->count())->toBe(1);

    $complete('agencies/x/brands/y/waves/z/concepts/q/images/nope.png')->assertUnprocessable()->assertJsonPath('errors.0.code', 'concept_image.unknown_upload');

    $ok = C::upload($w, $id, C::png());
    $ok->assertCreated();
    $key = WaveConceptImage::query()->where('status', 'ready')->value('storage_key');
    $complete((string) $key)->assertUnprocessable()->assertJsonPath('errors.0.code', 'concept_image.already_completed');

    $late = conceptImageInit($w, $id, ['mime_type' => 'image/png', 'declared_bytes' => 100000, 'original_filename' => 'a.png'])->json('data.upload_id');
    Storage::disk('media')->put($late, C::png());
    DB::table('wave_concept_images')->where('storage_key', $late)->update(['expires_at' => now()->subMinute()]);
    $complete($late)->assertUnprocessable()->assertJsonPath('errors.0.code', 'concept_image.unknown_upload');
});

it('an upload id of another concept cannot be completed on this one', function (): void {
    $w = S::world();
    $p = C::persona($w);
    $a = C::make($w, $p);
    $b = C::make($w, $p);
    $key = conceptImageInit($w, $a, ['mime_type' => 'image/png', 'declared_bytes' => 100000, 'original_filename' => 'a.png'])->json('data.upload_id');
    Storage::disk('media')->put($key, C::png());
    S::call($w['staff'], 'POST', S::u($w, "concepts/{$b}/images/uploads/complete"), ['upload_id' => $key])->assertUnprocessable()->assertJsonPath('errors.0.code', 'concept_image.unknown_upload');
});

it('limits: ten images and 300 MB counted from reservations (pending) and real sizes (ready)', function (): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));
    for ($i = 0; $i < 10; $i++) {
        conceptImageInit($w, $id, ['mime_type' => 'image/png', 'declared_bytes' => 1000, 'original_filename' => "a{$i}.png"])->assertCreated();
    }
    conceptImageInit($w, $id, ['mime_type' => 'image/png', 'declared_bytes' => 1000, 'original_filename' => 'a.png'])->assertUnprocessable()->assertJsonPath('errors.0.code', 'concept_image.limit_count');

    $other = C::make($w, C::persona($w));
    conceptImageInit($w, $other, ['mime_type' => 'image/png', 'declared_bytes' => 200 * 1024 * 1024, 'original_filename' => 'big.png'])->assertCreated();
    conceptImageInit($w, $other, ['mime_type' => 'image/png', 'declared_bytes' => 100 * 1024 * 1024 + 1, 'original_filename' => 'big2.png'])->assertUnprocessable()->assertJsonPath('errors.0.code', 'concept_image.limit_bytes');
    conceptImageInit($w, $other, ['mime_type' => 'image/png', 'declared_bytes' => 100 * 1024 * 1024, 'original_filename' => 'big3.png'])->assertCreated();

    // An EXPIRED reservation no longer counts.
    DB::table('wave_concept_images')->update(['expires_at' => now()->subMinute()]);
    conceptImageInit($w, $other, ['mime_type' => 'image/png', 'declared_bytes' => 250 * 1024 * 1024, 'original_filename' => 'again.png'])->assertCreated();
});

it('the bytes are those of the stored object: a small real file frees the larger reservation', function (): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));
    $png = C::png();
    C::upload($w, $id, $png, declared: 250 * 1024 * 1024)->assertCreated();
    expect((int) DB::table('wave_concept_images')->value('size_bytes'))->toBe(strlen($png));
    conceptImageInit($w, $id, ['mime_type' => 'image/png', 'declared_bytes' => 250 * 1024 * 1024, 'original_filename' => 'b.png'])->assertCreated();
});

it('reads go through the API: file and thumbnail are streamed with nosniff and a private cache; no presigned URL anywhere', function (): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));
    $bytes = C::webp(50, 40);
    $image = C::upload($w, $id, $bytes, 'image/webp', 'pic one.webp')->assertCreated()->json('data');

    $list = S::call($w['admin'], 'GET', S::u($w, 'concepts?market=SK'));
    $json = $list->getContent();
    expect($json)->not->toContain('X-Amz')->and($json)->not->toContain('storage_key')->and($json)->not->toContain('agencies/')->and($json)->not->toContain('https://');
    $card = $list->json('data.0.attributes.images.0');
    expect($card['file_url'])->toStartWith('/api/v1/agencies/')->and($card['thumbnail_url'])->toEndWith('/thumbnail');

    $file = S::call($w['staff'], 'GET', S::u($w, "concepts/{$id}/images/{$image['id']}/file"))->assertOk();
    expect($file->headers->get('Content-Type'))->toBe('image/webp')
        ->and($file->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($file->headers->get('Cache-Control'))->toContain('private')
        ->and($file->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($file->streamedContent())->toBe($bytes);
    $download = S::call($w['staff'], 'GET', S::u($w, "concepts/{$id}/images/{$image['id']}/file?download=1"))->assertOk();
    expect($download->headers->get('Content-Disposition'))->toStartWith('attachment')->and($download->headers->get('Content-Disposition'))->toContain('pic one.webp');
    $thumb = S::call($w['staff'], 'GET', S::u($w, "concepts/{$id}/images/{$image['id']}/thumbnail"))->assertOk();
    expect($thumb->headers->get('Content-Type'))->toBe('image/jpeg')->and($thumb->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and((getimagesizefromstring($thumb->streamedContent()) ?: [2 => 0])[2])->toBe(IMAGETYPE_JPEG);
});

it('reads are authenticated and scoped: another agency, a creator and a missing object are refused or 404', function (): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));
    $image = C::upload($w, $id, C::png())->assertCreated()->json('data.id');
    $url = S::u($w, "concepts/{$id}/images/{$image}/file");

    S::call($w['otherAdmin'], 'GET', $url)->assertNotFound();
    auth()->guard('web')->logout();
    $this->getJson($url)->assertUnauthorized();
    $key = (string) DB::table('wave_concept_images')->value('storage_key');
    Storage::disk('media')->delete($key);
    S::call($w['staff'], 'GET', $url)->assertNotFound();
    S::call($w['staff'], 'GET', S::u($w, "concepts/{$id}/images/01HZZZZZZZZZZZZZZZZZZZZZZZ/file"))->assertNotFound();
});

it('a reservation that was never completed cannot be read, even when an object sits at its key', function (): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));
    $init = conceptImageInit($w, $id, ['mime_type' => 'image/png', 'declared_bytes' => 1000, 'original_filename' => 'a.png'])->assertCreated()->json('data.upload_id');
    Storage::disk('media')->put($init, C::png());
    $ulid = (string) DB::table('wave_concept_images')->value('ulid');

    S::call($w['staff'], 'GET', S::u($w, "concepts/{$id}/images/{$ulid}/file"))->assertNotFound();
    S::call($w['staff'], 'GET', S::u($w, "concepts/{$id}/images/{$ulid}/thumbnail"))->assertNotFound();
});

it('the thumbnail endpoint is a 404 when there is no thumbnail (the web shows the placeholder), and the original still downloads', function (): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));
    $image = C::upload($w, $id, C::png())->assertCreated()->json('data.id');
    $thumbKey = (string) DB::table('wave_concept_images')->value('thumb_key');
    DB::table('wave_concept_images')->update(['thumb_key' => null]);
    Storage::disk('media')->delete($thumbKey);

    S::call($w['staff'], 'GET', S::u($w, "concepts/{$id}/images/{$image}/thumbnail"))->assertNotFound();
    S::call($w['staff'], 'GET', S::u($w, "concepts/{$id}/images/{$image}/file"))->assertOk();
    expect(S::call($w['staff'], 'GET', S::u($w, 'concepts?market=SK'))->json('data.0.attributes.images.0'))->toMatchArray(['has_thumbnail' => false, 'thumbnail_url' => null]);
});

it('Q9: a decompression bomb (header 30000 x 30000, a few bytes) gets no thumbnail, does not take the worker down, and still downloads', function (): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));
    $bomb = bombPng(30000, 30000);
    expect(strlen($bomb))->toBeLessThan(200);

    $before = memory_get_peak_usage(true);
    $res = C::upload($w, $id, $bomb)->assertCreated();
    $delta = memory_get_peak_usage(true) - $before;

    $row = WaveConceptImage::query()->firstOrFail();
    expect($row->status)->toBe('ready')->and($row->thumb_key)->toBeNull()->and($res->json('data.has_thumbnail'))->toBeFalse()
        ->and($delta)->toBeLessThan(64 * 1024 * 1024);
    S::call($w['staff'], 'GET', S::u($w, "concepts/{$id}/images/{$row->ulid}/file"))->assertOk();
    expect(Storage::disk('media')->get($row->storage_key))->toBe($bomb);
    // The job, run again on its own (as a worker would), stays quiet.
    (new GenerateConceptImageThumbnail($row->id))->handle(app(ConceptThumbnailer::class));
    expect(WaveConceptImage::query()->firstOrFail()->thumb_key)->toBeNull();
});

it('Q9: the pixel and side thresholds stop the decoder; a corrupt body inside them reaches it and fails soft', function (array $size, bool $decoderReached): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));
    Log::spy();
    C::upload($w, $id, bombPng($size[0], $size[1]))->assertCreated();
    expect(WaveConceptImage::query()->firstOrFail()->thumb_key)->toBeNull();
    // Only a decode error logs "thumbnail failed"; a threshold refusal logs at info and never gets that far.
    if ($decoderReached) {
        Log::shouldHaveReceived('warning')->once();
    } else {
        Log::shouldNotHaveReceived('warning');
    }
})->with([
    'over 40 MP' => [[7000, 6000], false],
    'one side over 30000' => [[30001, 10], false],
    'a corrupt body inside the limits: fails soft' => [[100, 100], true],
]);

it('Q9: an image over the byte threshold gets the placeholder and still downloads', function (): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));
    $image = C::upload($w, $id, C::png())->assertCreated();
    $row = WaveConceptImage::query()->firstOrFail();
    Storage::disk('media')->delete((string) $row->thumb_key);
    $row->forceFill(['thumb_key' => null, 'size_bytes' => ConceptThumbnailer::MAX_BYTES + 1])->save();

    expect(app(ConceptThumbnailer::class)->generate($row->refresh()))->toBeFalse()->and($row->refresh()->thumb_key)->toBeNull();
    S::call($w['staff'], 'GET', S::u($w, "concepts/{$id}/images/{$image->json('data.id')}/file"))->assertOk();
});

it('delete: the object and its thumbnail go first, then the row; a decided concept goes back to Awaiting', function (): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));
    $image = C::upload($w, $id, C::png())->assertCreated()->json('data.id');
    $row = WaveConceptImage::query()->firstOrFail();
    $keys = [$row->storage_key, $row->thumb_key];
    C::decide($w, $id, 'approved')->assertOk();

    S::call($w['staff'], 'DELETE', S::u($w, "concepts/{$id}/images/{$image}"))->assertOk()->assertJsonPath('meta.reset_to_awaiting', true);
    foreach ($keys as $key) {
        Storage::disk('media')->assertMissing((string) $key);
    }
    expect(WaveConceptImage::query()->count())->toBe(0)->and((string) DB::table('wave_concepts')->value('status'))->toBe('awaiting');
    S::call($w['staff'], 'DELETE', S::u($w, "concepts/{$id}/images/{$image}"))->assertNotFound();
});

it('Q6: adding an image sends an Approved concept back to Awaiting; Changes requested stays', function (): void {
    $w = S::world();
    $p = C::persona($w);
    $a = C::make($w, $p);
    $b = C::make($w, $p);
    C::decide($w, $a, 'approved')->assertOk();
    C::decide($w, $b, 'changes_requested')->assertOk();

    C::upload($w, $a, C::png())->assertCreated();
    C::upload($w, $b, C::png())->assertCreated();
    expect(DB::table('wave_concepts')->where('ulid', $a)->value('status'))->toBe('awaiting')->and(DB::table('wave_concepts')->where('ulid', $b)->value('status'))->toBe('changes_requested');
});

it('delete concept removes every object; duplicate shares no object with its source', function (): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));
    C::upload($w, $id, C::png(30, 20))->assertCreated();
    C::upload($w, $id, C::jpeg(30, 20), 'image/jpeg', 'b.jpg')->assertCreated();
    $sourceKeys = DB::table('wave_concept_images')->get(['storage_key', 'thumb_key'])->flatMap(fn ($r) => [$r->storage_key, $r->thumb_key])->filter()->values()->all();

    $copy = S::call($w['staff'], 'POST', S::u($w, "concepts/{$id}/duplicate"))->assertCreated()->json('data');
    expect($copy['attributes']['images'])->toHaveCount(2);
    $copyConceptId = (int) DB::table('wave_concepts')->where('ulid', $copy['id'])->value('id');
    $copyKeys = DB::table('wave_concept_images')->where('concept_id', $copyConceptId)->get(['storage_key', 'thumb_key'])->flatMap(fn ($r) => [$r->storage_key, $r->thumb_key])->filter()->values()->all();

    expect(array_intersect($sourceKeys, $copyKeys))->toBe([])->and($copyKeys)->toHaveCount(4);
    foreach ([...$sourceKeys, ...$copyKeys] as $key) {
        Storage::disk('media')->assertExists($key);
    }
    expect(Storage::disk('media')->get($copyKeys[0]))->toBe(Storage::disk('media')->get($sourceKeys[0]));
    expect(DB::table('wave_concept_images')->where('concept_id', $copyConceptId)->pluck('status')->unique()->all())->toBe(['ready']);

    // Deleting the source leaves the copy's objects; deleting the copy removes its own.
    S::call($w['admin'], 'DELETE', S::u($w, "concepts/{$id}"))->assertNoContent();
    foreach ($sourceKeys as $key) {
        Storage::disk('media')->assertMissing($key);
    }
    foreach ($copyKeys as $key) {
        Storage::disk('media')->assertExists($key);
    }
    S::call($w['admin'], 'DELETE', S::u($w, "concepts/{$copy['id']}"))->assertNoContent();
    expect(Storage::disk('media')->allFiles())->toBe([])->and(DB::table('wave_concept_images')->count())->toBe(0);
    expect(C::lastAudit('wave_concept.duplicated')['images'] ?? null)->toBe(2);
});

it('the sweeper removes pending reservations an hour past their expiry (object first) and nothing else', function (): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));
    $ready = C::upload($w, $id, C::png())->assertCreated()->json('data.id');

    $abandoned = conceptImageInit($w, $id, ['mime_type' => 'image/png', 'declared_bytes' => 1000, 'original_filename' => 'old.png'])->json('data.upload_id');
    $recent = conceptImageInit($w, $id, ['mime_type' => 'image/png', 'declared_bytes' => 1000, 'original_filename' => 'new.png'])->json('data.upload_id');
    $justExpired = conceptImageInit($w, $id, ['mime_type' => 'image/png', 'declared_bytes' => 1000, 'original_filename' => 'mid.png'])->json('data.upload_id');
    foreach ([$abandoned, $recent, $justExpired] as $key) {
        Storage::disk('media')->put($key, 'partial');
    }
    DB::table('wave_concept_images')->where('ulid', $ready)->update(['expires_at' => now()->subHours(3)]);   // a ready row is never swept, whatever its expiry says
    DB::table('wave_concept_images')->where('storage_key', $abandoned)->update(['expires_at' => now()->subHours(2)]);
    DB::table('wave_concept_images')->where('storage_key', $justExpired)->update(['expires_at' => now()->subMinutes(30)]);

    Artisan::call('strategy:sweep-pending-concept-images', ['--dry-run' => true]);
    expect(DB::table('wave_concept_images')->count())->toBe(4);
    Storage::disk('media')->assertExists($abandoned);

    Artisan::call('strategy:sweep-pending-concept-images');
    Storage::disk('media')->assertMissing($abandoned);
    Storage::disk('media')->assertExists($recent);
    Storage::disk('media')->assertExists($justExpired);
    expect(DB::table('wave_concept_images')->pluck('storage_key')->all())->not->toContain($abandoned)->toContain($recent, $justExpired)
        ->and(DB::table('wave_concept_images')->where('ulid', $ready)->exists())->toBeTrue()->and(DB::table('wave_concept_images')->where('status', 'ready')->count())->toBe(1);
});

it('the sweeper is registered with the scheduler every fifteen minutes and without overlap', function (): void {
    Artisan::call('schedule:list');   // builds the schedule from bootstrap/app.php
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'strategy:sweep-pending-concept-images'));
    $event = $events->first();
    expect($events)->toHaveCount(1)->and($event?->expression)->toBe('*/15 * * * *')->and($event?->withoutOverlapping)->toBeTrue()
        ->and(array_key_exists('strategy:sweep-pending-concept-images', Artisan::all()))->toBeTrue()
        ->and(SweepPendingConceptImages::class)->toBeString();
});

it('roles: staff manage images; a creator is refused', function (): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));
    $creator = User::factory()->creator()->createOne();
    expect(S::call($creator, 'POST', S::u($w, "concepts/{$id}/images/uploads/init"), ['mime_type' => 'image/png', 'declared_bytes' => 10, 'original_filename' => 'a.png'])->status())->toBeIn([403, 404]);
    C::upload($w, $id, C::png(), as: $w['staff'])->assertCreated();
    expect(WaveConceptImage::query()->count())->toBe(1);
});
