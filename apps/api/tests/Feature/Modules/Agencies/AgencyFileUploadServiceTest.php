<?php

declare(strict_types=1);

use App\Core\Storage\AttachmentMimeTypes;
use App\Core\Storage\StorageDeleteFailedException;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyFile;
use App\Modules\Agencies\Services\AgencyFileUploadService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media');
});

it('initiatePresignedUpload returns a synthetic URL scoped under the agency files prefix', function (): void {
    $agency = Agency::factory()->create();

    $result = app(AgencyFileUploadService::class)
        ->initiatePresignedUpload($agency, 'application/pdf', 1024);

    expect($result)->toHaveKeys(['upload_url', 'upload_id', 'storage_path', 'expires_at', 'max_bytes'])
        ->and($result['upload_id'])->toStartWith("agencies/{$agency->ulid}/files/")
        ->and($result['upload_id'])->toEndWith('.pdf')
        ->and($result['storage_path'])->toBe($result['upload_id'])
        ->and($result['max_bytes'])->toBe(25 * 1024 * 1024);
});

it('initiatePresignedUpload rejects every VIDEOS entry (D3\'s subtraction)', function (string $mime): void {
    $agency = Agency::factory()->create();

    expect(fn () => app(AgencyFileUploadService::class)->initiatePresignedUpload($agency, $mime, 1024))
        ->toThrow(RuntimeException::class, 'Unsupported file MIME type');
})->with(array_keys(AttachmentMimeTypes::VIDEOS));

it('initiatePresignedUpload rejects an unsupported/garbage MIME type', function (): void {
    $agency = Agency::factory()->create();

    expect(fn () => app(AgencyFileUploadService::class)
        ->initiatePresignedUpload($agency, 'application/octet-stream', 1024))
        ->toThrow(RuntimeException::class, 'Unsupported file MIME type');
});

it('initiatePresignedUpload rejects a zero declared size', function (): void {
    $agency = Agency::factory()->create();

    expect(fn () => app(AgencyFileUploadService::class)
        ->initiatePresignedUpload($agency, 'application/pdf', 0))
        ->toThrow(RuntimeException::class, 'at least 1 byte');
});

it('initiatePresignedUpload rejects a declared size over 25MB', function (): void {
    $agency = Agency::factory()->create();

    expect(fn () => app(AgencyFileUploadService::class)
        ->initiatePresignedUpload($agency, 'application/pdf', 26 * 1024 * 1024))
        ->toThrow(RuntimeException::class, 'exceeds 25MB');
});

it('completePresignedUpload derives mime + size from the landed object, not the client', function (): void {
    $agency = Agency::factory()->create();
    $path = "agencies/{$agency->ulid}/files/abc.pdf";
    Storage::disk('media')->put($path, str_repeat('a', 2048));

    $result = app(AgencyFileUploadService::class)->completePresignedUpload($agency, $path);

    expect($result)->toBe([
        'storage_key' => $path,
        'mime_type' => 'application/pdf',
        'size_bytes' => 2048,
    ]);
});

it('completePresignedUpload rejects an upload_id outside the agency\'s own prefix', function (): void {
    $agencyA = Agency::factory()->create();
    $agencyB = Agency::factory()->create();
    $forgedPath = "agencies/{$agencyB->ulid}/files/forged.pdf";
    Storage::disk('media')->put($forgedPath, 'x');

    expect(fn () => app(AgencyFileUploadService::class)->completePresignedUpload($agencyA, $forgedPath))
        ->toThrow(RuntimeException::class, 'does not belong to this agency');
});

it('completePresignedUpload rejects an upload_id whose object never landed', function (): void {
    $agency = Agency::factory()->create();
    $path = "agencies/{$agency->ulid}/files/never-landed.pdf";

    expect(fn () => app(AgencyFileUploadService::class)->completePresignedUpload($agency, $path))
        ->toThrow(RuntimeException::class, 'not found at the expected path');
});

it('completePresignedUpload rejects a landed object with an unrecognized extension', function (): void {
    $agency = Agency::factory()->create();
    $path = "agencies/{$agency->ulid}/files/malicious.exe";
    Storage::disk('media')->put($path, 'x');

    expect(fn () => app(AgencyFileUploadService::class)->completePresignedUpload($agency, $path))
        ->toThrow(RuntimeException::class, 'unrecognized extension');
});

it('completePresignedUpload rejects an empty (0-byte) landed object', function (): void {
    $agency = Agency::factory()->create();
    $path = "agencies/{$agency->ulid}/files/empty.pdf";
    Storage::disk('media')->put($path, '');

    expect(fn () => app(AgencyFileUploadService::class)->completePresignedUpload($agency, $path))
        ->toThrow(RuntimeException::class, 'empty');
});

it('completePresignedUpload rejects a landed object over the 25MB cap', function (): void {
    $agency = Agency::factory()->create();
    $path = "agencies/{$agency->ulid}/files/huge.pdf";
    Storage::disk('media')->put($path, str_repeat('a', 26 * 1024 * 1024));

    expect(fn () => app(AgencyFileUploadService::class)->completePresignedUpload($agency, $path))
        ->toThrow(RuntimeException::class, 'exceeds the 25MB limit');
});

it('delete removes the object first, only then is the caller expected to soft-delete the row', function (): void {
    $agency = Agency::factory()->create();
    $file = AgencyFile::factory()->forAgency($agency->id)->create([
        'storage_key' => "agencies/{$agency->ulid}/files/to-delete.pdf",
    ]);
    Storage::disk('media')->put($file->storage_key, 'x');

    app(AgencyFileUploadService::class)->delete($agency, $file);

    Storage::disk('media')->assertMissing($file->storage_key);
});

it('delete rejects a storage_key outside the agency\'s own prefix (defense in depth)', function (): void {
    $agencyA = Agency::factory()->create();
    $agencyB = Agency::factory()->create();
    $file = AgencyFile::factory()->forAgency($agencyB->id)->create([
        'storage_key' => "agencies/{$agencyB->ulid}/files/other.pdf",
    ]);

    expect(fn () => app(AgencyFileUploadService::class)->delete($agencyA, $file))
        ->toThrow(RuntimeException::class, 'does not belong to this agency');
});

it('delete throws StorageDeleteFailedException when the disk reports failure, never swallowing it', function (): void {
    $agency = Agency::factory()->create();
    $file = AgencyFile::factory()->forAgency($agency->id)->create([
        'storage_key' => "agencies/{$agency->ulid}/files/never-lands.pdf",
    ]);
    // The object was never written, so the fake local disk's delete() call
    // itself succeeds (deleting a non-existent path is a no-op success) —
    // simulate the disk truly failing by swapping in a Storage double.
    Storage::shouldReceive('disk')->with('media')->andReturn(
        Mockery::mock(FilesystemAdapter::class, function ($mock) {
            $mock->shouldReceive('delete')->once()->andReturn(false);
        }),
    );

    expect(fn () => app(AgencyFileUploadService::class)->delete($agency, $file))
        ->toThrow(StorageDeleteFailedException::class);
});
