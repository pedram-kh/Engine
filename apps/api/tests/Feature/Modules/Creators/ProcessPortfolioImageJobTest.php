<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Database\Factories\CreatorPortfolioItemFactory;
use App\Modules\Creators\Enums\PortfolioProcessingStatus;
use App\Modules\Creators\Jobs\ProcessPortfolioImageJob;
use App\Modules\Creators\Services\PortfolioImageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-004 Q5 — the full-resolution EXIF-strip / thumbnail worker.
 *
 * These are the heavy image-decode tests the `composer test` 512 MB memory pin
 * exists for (see docs/reviews/ah-004-portfolio-overhaul-plan.md §6). The
 * over-cap case at MAX_MEGAPIXELS = 50 is the matched pair with that pin: the
 * guard rejects it from the HEADER (getimagesizefromstring) before any full
 * bitmap is allocated.
 */
function rawJpeg(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    assert($image !== false);

    ob_start();
    imagejpeg($image, null, 80);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

beforeEach(function (): void {
    Storage::fake('media');
});

it('sanitises a ready image at FULL resolution and generates a bounded thumbnail', function (): void {
    $creator = CreatorFactory::new()->createOne();
    $path = "creators/{$creator->ulid}/portfolio/01FULLRES000000000000000.jpg";
    Storage::disk('media')->put($path, rawJpeg(1600, 1200));

    $item = CreatorPortfolioItemFactory::new()->processing()->createOne([
        'creator_id' => $creator->id,
        's3_path' => $path,
        'mime_type' => 'image/jpeg',
        'thumbnail_path' => null,
    ]);

    (new ProcessPortfolioImageJob($item->id))->handle(new PortfolioImageProcessor);

    $item->refresh();
    expect($item->processing_status)->toBe(PortfolioProcessingStatus::Ready);
    expect($item->thumbnail_path)->not->toBeNull();
    Storage::disk('media')->assertExists($item->thumbnail_path);

    // Full-res retained — NOT the avatar 1024px downscale.
    $full = getimagesizefromstring((string) Storage::disk('media')->get($path));
    expect($full)->not->toBeFalse();
    expect($full[0])->toBe(1600)->and($full[1])->toBe(1200);

    // Thumbnail bounded to <=512px longest side.
    $thumb = getimagesizefromstring((string) Storage::disk('media')->get($item->thumbnail_path));
    expect($thumb)->not->toBeFalse();
    expect(max((int) $thumb[0], (int) $thumb[1]))->toBeLessThanOrEqual(PortfolioImageProcessor::THUMBNAIL_MAX_EDGE);
});

it('marks an OVER-CAP image failed via the megapixel guard (kept for delete/re-upload)', function (): void {
    $creator = CreatorFactory::new()->createOne();
    $path = "creators/{$creator->ulid}/portfolio/01OVERCAP000000000000000.jpg";
    // 8000 x 6500 = 52 MP, above MAX_MEGAPIXELS = 50.
    Storage::disk('media')->put($path, rawJpeg(8000, 6500));

    $item = CreatorPortfolioItemFactory::new()->processing()->createOne([
        'creator_id' => $creator->id,
        's3_path' => $path,
        'mime_type' => 'image/jpeg',
    ]);

    (new ProcessPortfolioImageJob($item->id))->handle(new PortfolioImageProcessor);

    $item->refresh();
    expect($item->processing_status)->toBe(PortfolioProcessingStatus::Failed);
    expect($item->thumbnail_path)->toBeNull();
});

it('marks a CORRUPT upload failed instead of hanging on processing', function (): void {
    $creator = CreatorFactory::new()->createOne();
    $path = "creators/{$creator->ulid}/portfolio/01CORRUPT000000000000000.jpg";
    Storage::disk('media')->put($path, 'this is not a real image');

    $item = CreatorPortfolioItemFactory::new()->processing()->createOne([
        'creator_id' => $creator->id,
        's3_path' => $path,
        'mime_type' => 'image/jpeg',
    ]);

    (new ProcessPortfolioImageJob($item->id))->handle(new PortfolioImageProcessor);

    expect($item->refresh()->processing_status)->toBe(PortfolioProcessingStatus::Failed);
});

it('is a no-op for an item that is already ready (idempotent on re-run)', function (): void {
    $creator = CreatorFactory::new()->createOne();
    $item = CreatorPortfolioItemFactory::new()->createOne([
        'creator_id' => $creator->id,
        's3_path' => "creators/{$creator->ulid}/portfolio/01READY00000000000000000.jpg",
        'processing_status' => PortfolioProcessingStatus::Ready,
        'thumbnail_path' => 'existing/thumb.jpg',
    ]);

    (new ProcessPortfolioImageJob($item->id))->handle(new PortfolioImageProcessor);

    $item->refresh();
    expect($item->processing_status)->toBe(PortfolioProcessingStatus::Ready);
    expect($item->thumbnail_path)->toBe('existing/thumb.jpg');
});
