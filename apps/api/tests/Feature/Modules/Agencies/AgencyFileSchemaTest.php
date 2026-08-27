<?php

declare(strict_types=1);

use App\Modules\Agencies\Enums\AgencyFileCategory;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyFile;
use App\Modules\Identity\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('builds an agency file via factory with tenancy, casts, and relations intact', function (): void {
    $agency = Agency::factory()->create();
    $uploader = User::factory()->create();

    $file = AgencyFile::factory()
        ->forAgency($agency->id)
        ->uploadedBy($uploader->id)
        ->create();

    expect($file->ulid)->not->toBeEmpty()
        ->and($file->agency->id)->toBe($agency->id)
        ->and($file->uploadedBy?->id)->toBe($uploader->id)
        ->and($file->category)->toBe(AgencyFileCategory::CampaignAttachment)
        ->and($file->size_bytes)->toBeInt()
        ->and($file->hasAttachments())->toBeFalse();
});

it('enforces the storage_key UNIQUE constraint', function (): void {
    $agency = Agency::factory()->create();
    $key = 'agencies/'.$agency->ulid.'/files/duplicate.pdf';

    AgencyFile::factory()->forAgency($agency->id)->create(['storage_key' => $key]);

    expect(fn () => AgencyFile::factory()->forAgency($agency->id)->create(['storage_key' => $key]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('soft-deletes: the row disappears from the default query but the S3 key is not touched by this layer', function (): void {
    $file = AgencyFile::factory()->create();
    $storageKey = $file->storage_key;

    $file->delete();

    expect(AgencyFile::query()->find($file->id))->toBeNull()
        ->and(AgencyFile::withTrashed()->find($file->id)?->storage_key)->toBe($storageKey)
        ->and(AgencyFile::withTrashed()->find($file->id)?->deleted_at)->not->toBeNull();
});

it('nulls uploaded_by_user_id when the uploading user is deleted, without touching the row', function (): void {
    $uploader = User::factory()->create();
    $file = AgencyFile::factory()->uploadedBy($uploader->id)->create();

    // A query-builder delete bypasses Eloquent model events (and the audit
    // listener's reason requirement) while still exercising the real FK
    // constraint's ON DELETE SET NULL behavior at the DB layer.
    DB::table('users')->where('id', $uploader->id)->delete();

    expect($file->refresh()->uploaded_by_user_id)->toBeNull();
});
