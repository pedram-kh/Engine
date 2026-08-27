<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\AgencyFile;
use App\Modules\Agencies\Services\AgencyFileUploadService;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * D4 review priority #2, satisfied properly per the plan-pause ruling:
 * asserts the ACTUAL `response-content-disposition` query parameter on a
 * REAL presigned URL, not the mere presence of an option passed to a mock.
 *
 * Deliberately does NOT call Storage::fake('media') — phpunit.xml already
 * configures the `media` disk with dummy MinIO credentials
 * (AWS_ACCESS_KEY_ID=minioadmin, AWS_ENDPOINT_URL=http://localhost:9100), so
 * `Storage::disk('media')` resolves to a genuine `AwsS3V3Adapter` wrapping a
 * real `Aws\S3\S3Client`. SigV4 presigned-URL signing is pure local
 * computation — it never makes a network call — so this exercises the exact
 * production code path (including the real `createPresignedRequest()` /
 * `temporaryUrl()` call) without needing MinIO or any object to actually
 * exist.
 */
it('mints a signed download URL with Content-Disposition: attachment and the sanitized filename', function (): void {
    expect(Storage::disk('media'))->toBeInstanceOf(AwsS3V3Adapter::class);

    $file = AgencyFile::factory()->create([
        'original_filename' => 'Q3 Report (final)!.pdf',
        'storage_key' => 'agencies/01ABC/files/01XYZ.pdf',
    ]);

    $url = app(AgencyFileUploadService::class)->signedDownloadUrl($file);

    expect($url)->not->toBeNull();

    $query = [];
    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

    expect($query)->toHaveKey('response-content-disposition')
        ->and($query['response-content-disposition'])->toBe('attachment; filename="Q3-Report-final.pdf"');
});

it('falls back to a ULID-based filename when the original filename sanitizes to nothing', function (): void {
    $file = AgencyFile::factory()->create([
        'original_filename' => '???.pdf',
        'storage_key' => 'agencies/01ABC/files/01XYZ.pdf',
    ]);

    $url = app(AgencyFileUploadService::class)->signedDownloadUrl($file);

    $query = [];
    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

    expect($query['response-content-disposition'])->toBe('attachment; filename="file-'.$file->ulid.'.pdf"');
});
