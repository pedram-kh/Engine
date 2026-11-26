<?php

declare(strict_types=1);

use App\Modules\Strategy\Console\SweepPendingScriptImages;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptImage;
use App\Modules\Strategy\Services\ScriptThumbnailer;
use App\Modules\Strategy\StrategyLimits;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ScriptFixtures as SF;
use Tests\Support\StrategyFixtures as S;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-3.2b I1 and I2 (ruling 11): reference images on a hook or a line. JPG, PNG, WebP and GIF; at most ten per block; 300 MB per
 * script counted at the reservation (pending included) and again at the verify; a real animated GIF gets a first-frame
 * thumbnail; deletes go objects first; the sweeper clears expired reservations. Tech-debt row 40: no virus scan before PL-4.
 */

beforeEach(fn () => Storage::fake('media'));

/** @return array{0: string, 1: WaveScript, 2: string, 3: string} the script ulid, the model, a line ulid and the first hook ulid */
function pl32bImageScript(array $w): array
{
    $id = SF::api($w);
    $script = WaveScript::query()->where('ulid', $id)->firstOrFail();
    $line = SF::line($script, 0, 'x');

    return [$id, $script, $line->ulid, SF::hooksOf($script)[0]->ulid];
}

/**
 * @param  array<string, mixed>  $w
 * @return TestResponse<Response>
 */
function pl32bInit(array $w, string $id, string $block, string $owner, int $bytes = 1000, string $mime = 'image/png'): TestResponse
{
    return S::call($w['staff'], 'POST', SF::url($w, "{$id}/images/uploads/init"), ['block' => $block, 'id' => $owner, 'mime_type' => $mime, 'declared_bytes' => $bytes, 'original_filename' => 'ref.png']);
}

it('I1: a hook and a line take an image: reserved, stored, verified from the stored object, ready with a thumbnail, shown on the script', function (): void {
    $w = SF::world();
    [$id, , $line, $hook] = pl32bImageScript($w);
    $bytes = SF::png();

    foreach ([['line', $line], ['hook', $hook]] as [$block, $owner]) {
        $done = SF::upload($w, $id, $block, $owner, $bytes)->assertCreated();
        expect($done->json('data'))->toMatchArray(['mime_type' => 'image/png', 'size_bytes' => strlen($bytes), 'has_thumbnail' => true]);
        expect($done->json('data'))->not->toHaveKeys(['storage_key', 'thumb_key']);
    }

    $shown = S::call($w['staff'], 'GET', SF::url($w, $id))->assertOk();
    expect($shown->json('data.lines.0.images'))->toHaveCount(1)->and($shown->json('data.hooks.0.images'))->toHaveCount(1)
        ->and($shown->json('data.usage.image_bytes'))->toBe(strlen($bytes) * 2)
        ->and($shown->json('data.lines.0.images.0.file_url'))->toStartWith('/api/v1/agencies/')->and($shown->json('data.lines.0.images.0.thumbnail_url'))->toEndWith('/thumbnail');

    $row = WaveScriptImage::query()->where('line_id', '!=', null)->firstOrFail();
    expect($row->storage_key)->toMatch('#^agencies/[0-9A-Z]{26}/brands/[0-9A-Z]{26}/waves/[0-9A-Z]{26}/scripts/[0-9A-Z]{26}/images/[0-9A-Z]{26}\.png$#');
    Storage::disk('media')->assertExists([(string) $row->storage_key, (string) $row->thumb_key]);
});

it('I1: JPG, PNG, WebP and GIF are accepted; SVG, PDF, HTML and an empty declaration are refused at the reservation', function (): void {
    $w = SF::world();
    [$id, , $line] = pl32bImageScript($w);

    foreach (['image/jpeg', 'image/png', 'image/webp', 'image/gif'] as $mime) {
        pl32bInit($w, $id, 'line', $line, 100, $mime)->assertCreated();
    }
    foreach (['image/svg+xml', 'application/pdf', 'text/html', ''] as $mime) {
        pl32bInit($w, $id, 'line', $line, 100, $mime)->assertStatus(422);
    }
    pl32bInit($w, $id, 'line', $line, 0)->assertStatus(422);
    expect(WaveScriptImage::query()->count())->toBe(4);
});

it('I1: a text file named .jpg, a type that does not match its name, and a larger file than reserved are refused at the verify and leave no object and no row', function (): void {
    $w = SF::world();
    [$id, , $line] = pl32bImageScript($w);

    SF::upload($w, $id, 'line', $line, '<script>alert(1)</script>', 'image/jpeg', 'evil.jpg')->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script_image.invalid');
    SF::upload($w, $id, 'line', $line, SF::png(), 'image/jpeg', 'wrong.jpg')->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script_image.invalid');
    SF::upload($w, $id, 'line', $line, SF::png(), 'image/png', 'big.png', declared: 10)->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script_image.invalid');
    expect(WaveScriptImage::query()->count())->toBe(0)->and(Storage::disk('media')->allFiles())->toBe([]);
});

it('I1: complete before the object is there is refused but keeps the reservation, so the same upload can finish; a second complete is refused', function (): void {
    $w = SF::world();
    [$id, , $line] = pl32bImageScript($w);
    $init = pl32bInit($w, $id, 'line', $line, 5000)->assertCreated();
    $complete = fn () => S::call($w['staff'], 'POST', SF::url($w, "{$id}/images/uploads/complete"), ['upload_id' => $init->json('data.upload_id')]);

    $complete()->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script_image.invalid');
    expect(WaveScriptImage::query()->where('status', 'pending')->count())->toBe(1);
    Storage::disk('media')->put((string) $init->json('data.upload_id'), SF::png());
    $complete()->assertCreated();
    SF::rearm();
    $complete()->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script_image.already_completed');
    S::call($w['staff'], 'POST', SF::url($w, "{$id}/images/uploads/complete"), ['upload_id' => 'agencies/other/key.png'])->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script_image.unknown_upload');
});

it('I1: a block takes at most 10 images, counting a pending reservation; another block of the same script still takes its own ten', function (): void {
    $w = SF::world();
    [$id, , $line, $hook] = pl32bImageScript($w);

    for ($i = 0; $i < StrategyLimits::IMAGES_PER_BLOCK; $i++) {
        pl32bInit($w, $id, 'line', $line)->assertCreated();
    }
    pl32bInit($w, $id, 'line', $line)->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script_image.limit_count')->assertJsonPath('errors.0.meta.max', 10);
    pl32bInit($w, $id, 'hook', $hook)->assertCreated();
    expect(WaveScriptImage::query()->count())->toBe(11);
});

it('I1: a script takes at most 300 MB of images, pending reservations included, at the reservation and again at the verify with the real size', function (): void {
    $w = SF::world();
    [$id, $script, $line, $hook] = pl32bImageScript($w);
    $cap = StrategyLimits::SCRIPT_IMAGE_TOTAL_BYTES;

    pl32bInit($w, $id, 'line', $line, $cap - 100)->assertCreated();
    pl32bInit($w, $id, 'hook', $hook, 101)->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script_image.limit_bytes');
    pl32bInit($w, $id, 'hook', $hook, 100)->assertCreated();
    expect(WaveScriptImage::query()->where('script_id', $script->id)->count())->toBe(2);

    // the verify checks again with the REAL size: the ready images already fill the script, a reservation made earlier cannot add
    $pending = WaveScriptImage::query()->where('script_id', $script->id)->where('declared_bytes', 100)->firstOrFail();
    WaveScriptImage::query()->where('script_id', $script->id)->where('declared_bytes', $cap - 100)->update(['status' => 'ready', 'size_bytes' => $cap - 50, 'mime_type' => 'image/png']);
    $pending->forceFill(['declared_bytes' => 5000])->save();
    Storage::disk('media')->put((string) $pending->storage_key, SF::png());
    S::call($w['staff'], 'POST', SF::url($w, "{$id}/images/uploads/complete"), ['upload_id' => $pending->storage_key])
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script_image.limit_bytes');
    expect(WaveScriptImage::query()->whereKey($pending->id)->exists())->toBeFalse();
    Storage::disk('media')->assertMissing((string) $pending->storage_key);
});

it('I1: a real animated GIF is accepted and gets a first-frame JPEG thumbnail', function (): void {
    $w = SF::world();
    [$id, , $line] = pl32bImageScript($w);
    $gif = SF::animatedGif();
    expect(substr_count($gif, "\x21\xF9\x04"))->toBe(2);

    $done = SF::upload($w, $id, 'line', $line, $gif, 'image/gif', 'moving.gif')->assertCreated();
    expect($done->json('data.mime_type'))->toBe('image/gif')->and($done->json('data.has_thumbnail'))->toBeTrue();

    $row = WaveScriptImage::query()->firstOrFail();
    $thumb = S::call($w['staff'], 'GET', SF::url($w, "{$id}/images/{$row->ulid}/thumbnail"))->assertOk();
    expect($thumb->headers->get('Content-Type'))->toBe('image/jpeg')->and((getimagesizefromstring($thumb->streamedContent()) ?: [2 => 0])[2])->toBe(IMAGETYPE_JPEG);
    $file = S::call($w['staff'], 'GET', SF::url($w, "{$id}/images/{$row->ulid}/file"))->assertOk();
    expect($file->headers->get('Content-Type'))->toBe('image/gif')->and($file->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($file->streamedContent())->toBe($gif);
});

it('I1: reads go through the API with the user\'s session only; a pending image has no file; another script\'s image is 404', function (): void {
    $w = SF::world();
    [$id, , $line] = pl32bImageScript($w);
    $other = SF::api($w);
    SF::upload($w, $id, 'line', $line, SF::png())->assertCreated();
    $ready = WaveScriptImage::query()->firstOrFail();
    $init = pl32bInit($w, $id, 'line', $line)->assertCreated();
    $pending = WaveScriptImage::query()->where('status', 'pending')->firstOrFail();
    Storage::disk('media')->put((string) $init->json('data.upload_id'), SF::png());

    S::call($w['staff'], 'GET', SF::url($w, "{$id}/images/{$pending->ulid}/file"))->assertNotFound();
    S::call($w['staff'], 'GET', SF::url($w, "{$other}/images/{$ready->ulid}/file"))->assertNotFound();
    S::call($w['otherAdmin'], 'GET', SF::url($w, "{$id}/images/{$ready->ulid}/file"))->assertNotFound();
    S::call($w['staff'], 'GET', SF::url($w, "{$id}/images/{$ready->ulid}/file"))->assertOk();
});

it('I1: images reorder by the complete list of one block; a stale list writes nothing', function (): void {
    $w = SF::world();
    [$id, , $line] = pl32bImageScript($w);
    foreach ([1, 2, 3] as $i) {
        SF::upload($w, $id, 'line', $line, SF::png(40 + $i), 'image/png', "r{$i}.png")->assertCreated();
    }
    $ids = WaveScriptImage::query()->orderBy('position')->pluck('ulid')->all();
    $body = static fn (array $list): array => ['block' => 'line', 'id' => $line, 'ids' => $list];

    $res = S::call($w['staff'], 'PUT', SF::url($w, "{$id}/images/order"), $body([$ids[2], $ids[0], $ids[1]]))->assertOk();
    expect(array_column($res->json('data.lines.0.images'), 'id'))->toBe([$ids[2], $ids[0], $ids[1]]);
    S::call($w['staff'], 'PUT', SF::url($w, "{$id}/images/order"), $body([$ids[2], $ids[0]]))->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script.order_mismatch');
    expect(WaveScriptImage::query()->orderBy('position')->pluck('ulid')->all())->toBe([$ids[2], $ids[0], $ids[1]]);
});

it('I2: removing an image deletes the objects first, then the row; a failed object delete changes nothing and says so', function (): void {
    $w = SF::world();
    [$id, , $line] = pl32bImageScript($w);
    SF::upload($w, $id, 'line', $line, SF::png())->assertCreated();
    $row = WaveScriptImage::query()->firstOrFail();
    $keys = [(string) $row->storage_key, (string) $row->thumb_key];

    // the disk refuses to delete
    $real = Storage::disk('media');
    $failing = Mockery::mock($real)->makePartial();
    $failing->shouldReceive('delete')->andReturn(false);
    Storage::set('media', $failing);
    S::call($w['staff'], 'DELETE', SF::url($w, "{$id}/images/{$row->ulid}"))->assertStatus(500)->assertJsonPath('errors.0.code', 'wave_script_image.delete_failed');
    expect(WaveScriptImage::query()->whereKey($row->id)->exists())->toBeTrue();
    Storage::set('media', $real);
    $real->assertExists($keys);

    SF::rearm();
    S::call($w['staff'], 'DELETE', SF::url($w, "{$id}/images/{$row->ulid}"))->assertOk();
    expect(WaveScriptImage::query()->count())->toBe(0);
    $real->assertMissing($keys);
});

it('I2: deleting a line removes the line\'s images and their objects; so does deleting a hook', function (): void {
    $w = SF::world();
    [$id, , $line, $hook] = pl32bImageScript($w);
    SF::upload($w, $id, 'line', $line, SF::png())->assertCreated();
    SF::upload($w, $id, 'hook', $hook, SF::png())->assertCreated();
    expect(Storage::disk('media')->allFiles())->toHaveCount(4);

    S::call($w['staff'], 'DELETE', SF::url($w, "{$id}/lines/{$line}"))->assertOk();
    S::call($w['staff'], 'DELETE', SF::url($w, "{$id}/hooks/{$hook}"))->assertOk();

    expect(WaveScriptImage::query()->count())->toBe(0)->and(Storage::disk('media')->allFiles())->toBe([]);
});

it('I2: a reservation deleted before its upload is completed cannot be completed any more', function (): void {
    $w = SF::world();
    [$id, , $line] = pl32bImageScript($w);
    $init = pl32bInit($w, $id, 'line', $line, 5000)->assertCreated();
    $row = WaveScriptImage::query()->firstOrFail();
    S::call($w['staff'], 'DELETE', SF::url($w, "{$id}/images/{$row->ulid}"))->assertOk();
    Storage::disk('media')->put((string) $init->json('data.upload_id'), SF::png());

    S::call($w['staff'], 'POST', SF::url($w, "{$id}/images/uploads/complete"), ['upload_id' => $init->json('data.upload_id')])->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script_image.unknown_upload');
    expect(WaveScriptImage::query()->count())->toBe(0);
});

it('I2: the sweeper removes a pending reservation more than an hour past its expiry, object first, and nothing else; a dry run deletes nothing', function (): void {
    $w = SF::world();
    [$id, , $line] = pl32bImageScript($w);
    SF::upload($w, $id, 'line', $line, SF::png())->assertCreated();
    $fresh = pl32bInit($w, $id, 'line', $line)->assertCreated();
    $old = pl32bInit($w, $id, 'line', $line)->assertCreated();
    Storage::disk('media')->put((string) $old->json('data.upload_id'), SF::png());
    WaveScriptImage::query()->where('storage_key', $old->json('data.upload_id'))->update(['expires_at' => now()->subHours(2)]);
    WaveScriptImage::query()->where('storage_key', $fresh->json('data.upload_id'))->update(['expires_at' => now()->subMinutes(10)]);

    $this->artisan('strategy:sweep-pending-script-images', ['--dry-run' => true])->assertSuccessful();
    expect(WaveScriptImage::query()->count())->toBe(3);

    $this->artisan('strategy:sweep-pending-script-images')->assertSuccessful();
    expect(WaveScriptImage::query()->count())->toBe(2)->and(WaveScriptImage::query()->where('storage_key', $old->json('data.upload_id'))->exists())->toBeFalse()
        ->and(WaveScriptImage::query()->where('status', 'ready')->count())->toBe(1);
    Storage::disk('media')->assertMissing((string) $old->json('data.upload_id'));
});

it('I2: the sweeper is on the scheduler every fifteen minutes, without overlap, next to the concept one', function (): void {
    Artisan::call('schedule:list');   // builds the schedule from bootstrap/app.php
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'strategy:sweep-pending-script-images'));
    expect($events)->toHaveCount(1)->and($events->first()?->expression)->toBe('*/15 * * * *')->and($events->first()?->withoutOverlapping)->toBeTrue()
        ->and(array_key_exists('strategy:sweep-pending-script-images', Artisan::all()))->toBeTrue()
        ->and(collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'strategy:sweep-pending-concept-images')))->toHaveCount(1)
        ->and(SweepPendingScriptImages::class)->toBeString();
});

it('I2: the thumbnail job fails soft: a corrupt image is ready without a thumbnail and the file still downloads', function (): void {
    $w = SF::world();
    [$id, , $line] = pl32bImageScript($w);
    // a PNG header with a body that does not decode
    $bad = substr(SF::png(), 0, 40);
    $done = SF::upload($w, $id, 'line', $line, $bad, 'image/png', 'broken.png')->assertCreated();
    expect($done->json('data.has_thumbnail'))->toBeFalse()->and(ScriptThumbnailer::class)->toBeString();
    $row = WaveScriptImage::query()->firstOrFail();
    S::call($w['staff'], 'GET', SF::url($w, "{$id}/images/{$row->ulid}/thumbnail"))->assertNotFound();
    S::call($w['staff'], 'GET', SF::url($w, "{$id}/images/{$row->ulid}/file"))->assertOk();
});
