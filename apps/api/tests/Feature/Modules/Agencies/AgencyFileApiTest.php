<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyFile;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media');
});

/** @return array{agency: Agency, admin: User} */
function filesFixture(): array
{
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    return compact('agency', 'admin');
}

function filesUrl(Agency $agency): string
{
    return "/api/v1/agencies/{$agency->ulid}/files";
}

function fileUrl(Agency $agency, AgencyFile $file): string
{
    return "/api/v1/agencies/{$agency->ulid}/files/{$file->ulid}";
}

/**
 * Drives the real init → PUT → complete sequence against the faked local
 * disk. Takes an authenticated-POST closure rather than the `TestCase`
 * itself — Larastan's Pest support resolves `$this` correctly inside an
 * `it()` closure's own `fn (...)` but analyzes a plain top-level function's
 * `TestCase $test` parameter against `Pest\PendingCalls\TestCall`, a false
 * positive (see phpstan.neon's documented Pest ignore for the sibling
 * `Call to an undefined method` class of this same mismatch). The
 * `EsignWebhookControllerTest` precedent avoids extracting the `$this`
 * context into a shared helper for the same reason; this file threads it
 * through as a typed closure instead.
 *
 * @param  Closure(string, array<string, mixed>): TestResponse<JsonResponse>  $post
 * @return TestResponse<JsonResponse>
 */
function uploadAgencyFile(Closure $post, Agency $agency, string $originalFilename = 'brief.pdf', string $mime = 'application/pdf', int $bytes = 2048): TestResponse
{
    $init = $post(filesUrl($agency).'/uploads/init', ['mime_type' => $mime, 'declared_bytes' => $bytes])
        ->assertOk()
        ->json('data');

    Storage::disk('media')->put($init['upload_id'], str_repeat('a', $bytes));

    return $post(filesUrl($agency).'/uploads/complete', [
        'upload_id' => $init['upload_id'],
        'original_filename' => $originalFilename,
    ]);
}

// ── Happy path ──────────────────────────────────────────────────────────────

it('uploads a file end to end (init → PUT → complete) and lists it', function (): void {
    ['agency' => $agency, 'admin' => $admin] = filesFixture();

    $post = fn (string $url, array $body): TestResponse => $this->actingAs($admin)->postJson($url, $body);
    $response = uploadAgencyFile($post, $agency)->assertCreated();

    expect($response->json('data.attributes.original_filename'))->toBe('brief.pdf')
        ->and($response->json('data.attributes.mime_type'))->toBe('application/pdf')
        ->and($response->json('data.attributes.size_bytes'))->toBe(2048)
        ->and($response->json('data.attributes.category'))->toBe('campaign_attachment')
        ->and($response->json('data.attributes.download_url'))->toBeNull() // local fake disk, no S3 adapter
        ->and($response->json('data'))->not->toHaveKey('storage_key');

    $this->actingAs($admin)->getJson(filesUrl($agency))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 1);
});

it('records the upload in the audit trail with the uploader attributed', function (): void {
    ['agency' => $agency, 'admin' => $admin] = filesFixture();

    $post = fn (string $url, array $body): TestResponse => $this->actingAs($admin)->postJson($url, $body);
    $response = uploadAgencyFile($post, $agency)->assertCreated();
    $fileId = $response->json('data.id');

    $file = AgencyFile::query()->where('ulid', $fileId)->first();

    expect($file?->uploaded_by_user_id)->toBe($admin->id);
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'agency_file.uploaded',
        'subject_id' => $file?->id,
    ]);
});

it('paginates newest-first and clamps per_page to [1, 100]', function (): void {
    ['agency' => $agency, 'admin' => $admin] = filesFixture();
    $older = AgencyFile::factory()->forAgency($agency->id)->create(['created_at' => now()->subDay()]);
    $newer = AgencyFile::factory()->forAgency($agency->id)->create(['created_at' => now()]);

    $response = $this->actingAs($admin)
        ->getJson(filesUrl($agency).'?per_page=500')
        ->assertOk();

    expect($response->json('meta.per_page'))->toBe(100)
        ->and($response->json('data.0.id'))->toBe($newer->ulid)
        ->and($response->json('data.1.id'))->toBe($older->ulid);
});

// ── Tenancy isolation (review priority #1) ─────────────────────────────────

it('never lists another agency\'s files', function (): void {
    ['agency' => $agencyA, 'admin' => $adminA] = filesFixture();
    $agencyB = Agency::factory()->createOne();
    AgencyFile::factory()->forAgency($agencyB->id)->create();

    $this->actingAs($adminA)->getJson(filesUrl($agencyA))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('404s (not 403) on cross-tenant show and delete — existence is not leaked', function (): void {
    ['agency' => $agencyA, 'admin' => $adminA] = filesFixture();
    $agencyB = Agency::factory()->createOne();
    $victim = AgencyFile::factory()->forAgency($agencyB->id)->create();

    // Through the attacker's own agency path.
    $this->actingAs($adminA)->getJson(fileUrl($agencyA, $victim))->assertNotFound();
    $this->actingAs($adminA)->deleteJson(fileUrl($agencyA, $victim))->assertNotFound();

    // And through the victim's own agency path — membership is missing.
    $this->actingAs($adminA)->getJson(fileUrl($agencyB, $victim))->assertNotFound();
    $this->actingAs($adminA)->deleteJson(fileUrl($agencyB, $victim))->assertNotFound();

    expect($victim->fresh())->not->toBeNull();
});

it('rejects a completed upload whose upload_id was forged outside the agency\'s own prefix', function (): void {
    ['agency' => $agencyA, 'admin' => $adminA] = filesFixture();
    $agencyB = Agency::factory()->createOne();
    $forgedPath = "agencies/{$agencyB->ulid}/files/forged.pdf";
    Storage::disk('media')->put($forgedPath, 'x');

    $this->actingAs($adminA)
        ->postJson(filesUrl($agencyA).'/uploads/complete', [
            'upload_id' => $forgedPath,
            'original_filename' => 'forged.pdf',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'agency_file.complete_failed');

    expect(AgencyFile::query()->count())->toBe(0);
});

// ── Roles (review priority: the ratified matrix) ───────────────────────────

it('lets every role list and upload, but only admin and manager delete', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $manager = User::factory()->agencyManager($agency)->createOne();
    $staff = User::factory()->agencyStaff($agency)->createOne();

    foreach ([$admin, $manager, $staff] as $member) {
        $this->actingAs($member)->getJson(filesUrl($agency))->assertOk();
    }

    $staffPost = fn (string $url, array $body): TestResponse => $this->actingAs($staff)->postJson($url, $body);
    $staffUpload = uploadAgencyFile($staffPost, $agency, 'staff-upload.pdf');
    $staffUpload->assertCreated();
    $staffFile = AgencyFile::query()->where('ulid', $staffUpload->json('data.id'))->firstOrFail();

    $this->actingAs($staff)->deleteJson(fileUrl($agency, $staffFile))->assertForbidden();
    expect($staffFile->fresh())->not->toBeNull();

    $managerFile = AgencyFile::factory()->forAgency($agency->id)->create();
    $this->actingAs($manager)->deleteJson(fileUrl($agency, $managerFile))->assertNoContent();

    $adminFile = AgencyFile::factory()->forAgency($agency->id)->create();
    $this->actingAs($admin)->deleteJson(fileUrl($agency, $adminFile))->assertNoContent();
});

it('refuses an unauthenticated request', function (): void {
    ['agency' => $agency] = filesFixture();

    $this->getJson(filesUrl($agency))->assertUnauthorized();
});

// ── MIME / size validation (D3, including the video subtraction) ──────────

it('rejects every video MIME on init — a document library, not media storage', function (string $videoMime): void {
    ['agency' => $agency, 'admin' => $admin] = filesFixture();

    $this->actingAs($admin)
        ->postJson(filesUrl($agency).'/uploads/init', ['mime_type' => $videoMime, 'declared_bytes' => 1024])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'agency_file.presign_failed');
})->with([
    'video/mp4',
    'video/quicktime',
    'video/webm',
]);

it('rejects a zero-byte declared size and an over-25MB declared size on init', function (): void {
    ['agency' => $agency, 'admin' => $admin] = filesFixture();

    $this->actingAs($admin)
        ->postJson(filesUrl($agency).'/uploads/init', ['mime_type' => 'application/pdf', 'declared_bytes' => 0])
        ->assertUnprocessable();

    $this->actingAs($admin)
        ->postJson(filesUrl($agency).'/uploads/init', ['mime_type' => 'application/pdf', 'declared_bytes' => 26 * 1024 * 1024])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'agency_file.presign_failed');
});

it('rejects an empty landed object and an over-cap landed object on complete', function (): void {
    ['agency' => $agency, 'admin' => $admin] = filesFixture();

    $emptyInit = $this->actingAs($admin)
        ->postJson(filesUrl($agency).'/uploads/init', ['mime_type' => 'application/pdf', 'declared_bytes' => 1024])
        ->json('data');
    Storage::disk('media')->put($emptyInit['upload_id'], '');
    $this->actingAs($admin)
        ->postJson(filesUrl($agency).'/uploads/complete', ['upload_id' => $emptyInit['upload_id'], 'original_filename' => 'empty.pdf'])
        ->assertUnprocessable();

    $hugeInit = $this->actingAs($admin)
        ->postJson(filesUrl($agency).'/uploads/init', ['mime_type' => 'application/pdf', 'declared_bytes' => 1024])
        ->json('data');
    Storage::disk('media')->put($hugeInit['upload_id'], str_repeat('a', 26 * 1024 * 1024));
    $this->actingAs($admin)
        ->postJson(filesUrl($agency).'/uploads/complete', ['upload_id' => $hugeInit['upload_id'], 'original_filename' => 'huge.pdf'])
        ->assertUnprocessable();

    expect(AgencyFile::query()->count())->toBe(0);
});

// ── Delete ordering + object-first (review priority #3) ────────────────────

it('deletes the object and hides the soft-deleted row from index/show', function (): void {
    ['agency' => $agency, 'admin' => $admin] = filesFixture();
    $post = fn (string $url, array $body): TestResponse => $this->actingAs($admin)->postJson($url, $body);
    $created = uploadAgencyFile($post, $agency)->assertCreated();
    $file = AgencyFile::query()->where('ulid', $created->json('data.id'))->firstOrFail();
    $storageKey = $file->storage_key;

    $this->actingAs($admin)->deleteJson(fileUrl($agency, $file))->assertNoContent();

    Storage::disk('media')->assertMissing($storageKey);
    $this->actingAs($admin)->getJson(fileUrl($agency, $file))->assertNotFound();
    $this->actingAs($admin)->getJson(filesUrl($agency))->assertJsonCount(0, 'data');
    expect(AgencyFile::withTrashed()->find($file->id)?->deleted_at)->not->toBeNull();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'agency_file.deleted',
        'subject_id' => $file->id,
    ]);
});

it('leaves the row fully visible when the object delete fails — never orphans bytes behind an invisible row', function (): void {
    ['agency' => $agency, 'admin' => $admin] = filesFixture();
    $file = AgencyFile::factory()->forAgency($agency->id)->create();

    $failing = Mockery::mock(Filesystem::class);
    $failing->shouldReceive('delete')->once()->andReturnFalse();
    Storage::set('media', $failing);

    $this->actingAs($admin)->deleteJson(fileUrl($agency, $file))->assertStatus(500);

    expect($file->fresh()?->deleted_at)->toBeNull();
    $this->assertDatabaseMissing('audit_logs', ['action' => 'agency_file.deleted', 'subject_id' => $file->id]);
});
