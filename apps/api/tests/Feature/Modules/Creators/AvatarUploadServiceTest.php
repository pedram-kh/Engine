<?php

declare(strict_types=1);

use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\AvatarUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media');
});

it('uploads an avatar to creators/{ulid}/avatar/ on the media disk', function (): void {
    $creator = Creator::factory()->createOne();
    $file = UploadedFile::fake()->image('avatar.jpg', 256, 256);

    $path = app(AvatarUploadService::class)->upload($creator, $file);

    expect($path)->toStartWith("creators/{$creator->ulid}/avatar/")
        ->and($path)->toEndWith('.jpg');

    Storage::disk('media')->assertExists($path);
});

it('rejects an avatar exceeding 5MB', function (): void {
    $creator = Creator::factory()->createOne();
    $file = UploadedFile::fake()->image('huge.jpg', 4000, 4000)->size(6 * 1024); // 6MB

    expect(fn () => app(AvatarUploadService::class)->upload($creator, $file))
        ->toThrow(RuntimeException::class, 'Avatar exceeds 5MB');
});

it('rejects unsupported MIME types', function (): void {
    $creator = Creator::factory()->createOne();
    $file = UploadedFile::fake()->create('payload.exe', 100, 'application/x-msdownload');

    expect(fn () => app(AvatarUploadService::class)->upload($creator, $file))
        ->toThrow(RuntimeException::class, 'Unsupported avatar MIME type');
});

it('accepts JPEG, PNG, and WebP', function (): void {
    $creator = Creator::factory()->createOne();

    foreach ([
        ['avatar.jpg', '.jpg'],
        ['avatar.png', '.png'],
        ['avatar.webp', '.webp'],
    ] as [$name, $expectedExt]) {
        $file = UploadedFile::fake()->image($name, 256, 256);
        $path = app(AvatarUploadService::class)->upload($creator, $file);
        expect($path)->toEndWith($expectedExt);
    }
});

it('per-creator path scopes prevent cross-creator overlap', function (): void {
    $a = Creator::factory()->createOne();
    $b = Creator::factory()->createOne();
    $file = UploadedFile::fake()->image('avatar.jpg', 256, 256);

    $pathA = app(AvatarUploadService::class)->upload($a, $file);
    $pathB = app(AvatarUploadService::class)->upload($b, $file);

    expect($pathA)->toContain("creators/{$a->ulid}/")
        ->and($pathB)->toContain("creators/{$b->ulid}/")
        ->and($pathA)->not->toBe($pathB);
});

it('delete() removes the path from the disk', function (): void {
    $creator = Creator::factory()->createOne();
    $file = UploadedFile::fake()->image('avatar.jpg', 256, 256);
    $path = app(AvatarUploadService::class)->upload($creator, $file);

    Storage::disk('media')->assertExists($path);

    app(AvatarUploadService::class)->delete($path);

    Storage::disk('media')->assertMissing($path);
});
