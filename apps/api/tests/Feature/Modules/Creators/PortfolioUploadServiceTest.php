<?php

declare(strict_types=1);

use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\PortfolioUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media');
});

it('uploadImage stores a portfolio image under the creator scope', function (): void {
    $creator = Creator::factory()->createOne();
    $file = UploadedFile::fake()->image('shot.jpg', 800, 600);

    $path = app(PortfolioUploadService::class)->uploadImage($creator, $file);

    expect($path)->toStartWith("creators/{$creator->ulid}/avatar/");
    Storage::disk('media')->assertExists($path);
});

it('uploadImage rejects files exceeding 10MB', function (): void {
    $creator = Creator::factory()->createOne();
    $file = UploadedFile::fake()->image('big.jpg', 4000, 4000)->size(11 * 1024); // 11MB

    expect(fn () => app(PortfolioUploadService::class)->uploadImage($creator, $file))
        ->toThrow(RuntimeException::class, 'exceeds 10MB');
});

it('uploadImage rejects non-image MIME types', function (): void {
    $creator = Creator::factory()->createOne();
    $file = UploadedFile::fake()->create('clip.mp4', 100, 'video/mp4');

    expect(fn () => app(PortfolioUploadService::class)->uploadImage($creator, $file))
        ->toThrow(RuntimeException::class, 'Unsupported image MIME type');
});

it('initiatePresignedUpload returns a synthetic URL on the local fake disk', function (): void {
    $creator = Creator::factory()->createOne();

    $result = app(PortfolioUploadService::class)
        ->initiatePresignedUpload($creator, 'video/mp4', 50 * 1024 * 1024);

    expect($result)->toHaveKeys(['url', 'upload_id', 'expires_at', 'max_bytes'])
        ->and($result['upload_id'])->toStartWith("creators/{$creator->ulid}/portfolio/")
        ->and($result['upload_id'])->toEndWith('.mp4')
        ->and($result['max_bytes'])->toBe(500 * 1024 * 1024);
});

it('initiatePresignedUpload rejects unsupported video MIME types', function (): void {
    $creator = Creator::factory()->createOne();

    expect(fn () => app(PortfolioUploadService::class)
        ->initiatePresignedUpload($creator, 'application/octet-stream', 1024))
        ->toThrow(RuntimeException::class, 'Unsupported video MIME type');
});

it('initiatePresignedUpload rejects declared sizes exceeding 500MB', function (): void {
    $creator = Creator::factory()->createOne();

    expect(fn () => app(PortfolioUploadService::class)
        ->initiatePresignedUpload($creator, 'video/mp4', 600 * 1024 * 1024))
        ->toThrow(RuntimeException::class, 'exceeds 500MB');
});

it('completePresignedUpload requires a creator-scoped upload_id', function (): void {
    $a = Creator::factory()->createOne();
    $b = Creator::factory()->createOne();

    // Pretend creator A's upload landed at the path for creator B.
    Storage::disk('media')->put("creators/{$b->ulid}/portfolio/01XYZ.mp4", 'fake');

    expect(fn () => app(PortfolioUploadService::class)
        ->completePresignedUpload($a, "creators/{$b->ulid}/portfolio/01XYZ.mp4"))
        ->toThrow(RuntimeException::class, 'does not belong');
});

it('completePresignedUpload returns the path when the object exists', function (): void {
    $creator = Creator::factory()->createOne();
    $path = "creators/{$creator->ulid}/portfolio/01ABCDEFG.mp4";
    Storage::disk('media')->put($path, 'fake video bytes');

    $returned = app(PortfolioUploadService::class)
        ->completePresignedUpload($creator, $path);

    expect($returned)->toBe($path);
});

it('completePresignedUpload throws when the object is missing', function (): void {
    $creator = Creator::factory()->createOne();
    $path = "creators/{$creator->ulid}/portfolio/01MISSING.mp4";

    expect(fn () => app(PortfolioUploadService::class)
        ->completePresignedUpload($creator, $path))
        ->toThrow(RuntimeException::class, 'not found');
});
