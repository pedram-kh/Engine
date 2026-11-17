<?php

declare(strict_types=1);

use App\Core\Tenancy\TenancyContext;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Enums\BrandStatus;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\BrandFile;
use App\Modules\Brands\Policies\BrandFilePolicy;
use App\Modules\Brands\Services\BrandFileUploadService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\WaveFixtures;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-1.1 — brand client files: flag gate (B1), tenancy (B3), roles (B7), rules (B9), audit (B10), shape (B12).
 */

beforeEach(function (): void {
    Storage::fake('media');
});

function bfUrl(Agency $agency, Brand $brand, string $suffix = ''): string
{
    return "/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}/files".($suffix !== '' ? '/'.$suffix : '');
}

/**
 * The real init, PUT, complete sequence against the faked disk (a typed closure for `$post`: see
 * AgencyFileApiTest for why `$this` is not threaded through a plain function).
 *
 * @param  Closure(string, array<string, mixed>): TestResponse<JsonResponse>  $post
 * @return TestResponse<JsonResponse>
 */
function bfUpload(Closure $post, Agency $agency, Brand $brand, string $name = 'brief.pdf', string $mime = 'application/pdf', int $bytes = 2048, string $type = 'brief'): TestResponse
{
    $init = $post(bfUrl($agency, $brand, 'uploads/init'), ['mime_type' => $mime, 'declared_bytes' => $bytes, 'file_type' => $type])
        ->assertOk()->json('data');

    Storage::disk('media')->put($init['upload_id'], str_repeat('a', $bytes));

    return $post(bfUrl($agency, $brand, 'uploads/complete'), [
        'upload_id' => $init['upload_id'], 'original_filename' => $name, 'file_type' => $type,
    ]);
}

/** @param int<0, max> $bytes */
function bfTruncateTo(string $path, int $bytes): bool
{
    $handle = fopen($path, 'r+');

    return $handle !== false && ftruncate($handle, $bytes) && fclose($handle);
}

it('B1: every client-file route answers 404 not_found while the flag is OFF, with no row, no object and no audit row', function (): void {
    $w = WaveFixtures::world();
    $file = BrandFile::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
    Storage::disk('media')->put($file->storage_key, 'x');
    $actor = $this->actingAs($w['admin'], 'web');

    $actor->getJson(bfUrl($w['agency'], $w['brand']))->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');
    $actor->postJson(bfUrl($w['agency'], $w['brand'], 'uploads/init'), ['mime_type' => 'application/pdf', 'declared_bytes' => 10, 'file_type' => 'brief'])
        ->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');
    $actor->postJson(bfUrl($w['agency'], $w['brand'], 'uploads/complete'), ['upload_id' => 'x', 'original_filename' => 'a.pdf', 'file_type' => 'brief'])
        ->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');
    $actor->getJson(bfUrl($w['agency'], $w['brand'], $file->ulid))->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');
    $actor->deleteJson(bfUrl($w['agency'], $w['brand'], $file->ulid))->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');

    expect(BrandFile::query()->count())->toBe(1)
        ->and(Storage::disk('media')->exists($file->storage_key))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'like', 'brand_file.%')->count())->toBe(0);
});

it('B1: route table: the five client-file routes are exactly the ones the flag-gate test covers', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(static fn ($r): bool => str_contains($r->uri(), '/brands/{brand}/files'))
        ->map(static fn ($r): string => implode('|', $r->methods()).' '.$r->uri())
        ->values()->all();

    expect($routes)->toHaveCount(5);
});

it('uploads end to end (init, PUT, complete), lists, shows, and deletes', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $post = fn (string $url, array $body): TestResponse => $this->actingAs($w['admin'], 'web')->postJson($url, $body);

    $res = bfUpload($post, $w['agency'], $w['brand'], 'Client brief.pdf')->assertCreated();
    $id = $res->json('data.id');

    expect($res->json('data.attributes.original_filename'))->toBe('Client brief.pdf')
        ->and($res->json('data.attributes.mime_type'))->toBe('application/pdf')
        ->and($res->json('data.attributes.size_bytes'))->toBe(2048)
        ->and($res->json('data.attributes.file_type'))->toBe('brief');

    $row = BrandFile::query()->firstOrFail();
    expect($row->storage_key)->toStartWith("agencies/{$w['agency']->ulid}/brands/{$w['brand']->ulid}/files/")
        ->and($row->uploaded_by_user_id)->toBe($w['admin']->id);

    $this->actingAs($w['admin'], 'web')->getJson(bfUrl($w['agency'], $w['brand']))->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('meta.total', 1);
    $this->actingAs($w['admin'], 'web')->getJson(bfUrl($w['agency'], $w['brand'], $id))->assertOk();
    $this->actingAs($w['admin'], 'web')->deleteJson(bfUrl($w['agency'], $w['brand'], $id))->assertNoContent();

    expect(BrandFile::query()->count())->toBe(0)
        ->and(Storage::disk('media')->exists($row->storage_key))->toBeFalse();
});

it('B3: agency B cannot list, init, complete, show or delete agency A\'s brand files, on either path', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $file = BrandFile::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
    Storage::disk('media')->put($file->storage_key, 'x');

    $call = function (string $method, string $url, array $body = []) use ($w): TestResponse {
        app(TenancyContext::class)->forget();

        return $this->actingAs($w['otherAdmin'], 'web')->json($method, $url, $body);
    };
    $initBody = ['mime_type' => 'application/pdf', 'declared_bytes' => 10, 'file_type' => 'brief'];
    $completeBody = ['upload_id' => $file->storage_key, 'original_filename' => 'a.pdf', 'file_type' => 'brief'];

    foreach ([$w['agency'], $w['otherAgency']] as $pathAgency) {
        $call('GET', bfUrl($pathAgency, $w['brand']))->assertNotFound();
        $call('POST', bfUrl($pathAgency, $w['brand'], 'uploads/init'), $initBody)->assertNotFound();
        $call('POST', bfUrl($pathAgency, $w['brand'], 'uploads/complete'), $completeBody)->assertNotFound();
        $call('GET', bfUrl($pathAgency, $w['brand'], $file->ulid))->assertNotFound();
        $call('DELETE', bfUrl($pathAgency, $w['brand'], $file->ulid))->assertNotFound();
    }
    // B's own brand with A's file ulid.
    $call('GET', bfUrl($w['otherAgency'], $w['otherBrand'], $file->ulid))->assertNotFound();
    $call('DELETE', bfUrl($w['otherAgency'], $w['otherBrand'], $file->ulid))->assertNotFound();

    expect(BrandFile::withoutGlobalScopes()->count())->toBe(1)
        ->and(Storage::disk('media')->exists($file->storage_key))->toBeTrue();
});

it('B3: an upload_id carrying agency A\'s prefix, submitted by agency B on B\'s own brand, is refused (prefix check)', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $forged = "agencies/{$w['agency']->ulid}/brands/{$w['brand']->ulid}/files/forged.pdf";
    Storage::disk('media')->put($forged, 'x');

    $this->actingAs($w['otherAdmin'], 'web')->postJson(bfUrl($w['otherAgency'], $w['otherBrand'], 'uploads/complete'), [
        'upload_id' => $forged, 'original_filename' => 'a.pdf', 'file_type' => 'brief',
    ])->assertUnprocessable()->assertJsonPath('errors.0.code', 'brand_file.complete_failed');

    expect(BrandFile::withoutGlobalScopes()->count())->toBe(0);
});

it('B3: a path of another brand of the SAME agency is refused too', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $brandTwo = Brand::factory()->forAgency($w['agency']->id)->createOne();
    $path = "agencies/{$w['agency']->ulid}/brands/{$brandTwo->ulid}/files/other.pdf";
    Storage::disk('media')->put($path, 'x');

    $this->actingAs($w['admin'], 'web')->postJson(bfUrl($w['agency'], $w['brand'], 'uploads/complete'), [
        'upload_id' => $path, 'original_filename' => 'a.pdf', 'file_type' => 'brief',
    ])->assertUnprocessable()->assertJsonPath('errors.0.code', 'brand_file.complete_failed');
});

it('B3: a file of brand 1 requested under brand 2 of the same agency is 404', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $brandTwo = Brand::factory()->forAgency($w['agency']->id)->createOne();
    $file = BrandFile::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();

    $this->actingAs($w['admin'], 'web')->getJson(bfUrl($w['agency'], $brandTwo, $file->ulid))->assertNotFound();
    $this->actingAs($w['admin'], 'web')->deleteJson(bfUrl($w['agency'], $brandTwo, $file->ulid))->assertNotFound();
    $this->actingAs($w['admin'], 'web')->getJson(bfUrl($w['agency'], $brandTwo))->assertOk()->assertJsonPath('meta.total', 0);
});

it('B7: admin and manager upload and delete; staff cannot, but can list, show and download', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $file = BrandFile::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
    Storage::disk('media')->put($file->storage_key, 'x');

    foreach (['admin', 'manager'] as $role) {
        $post = fn (string $url, array $body): TestResponse => $this->actingAs($w[$role], 'web')->postJson($url, $body);
        $up = bfUpload($post, $w['agency'], $w['brand'], "by-{$role}.pdf")->assertCreated();
        $this->actingAs($w[$role], 'web')->deleteJson(bfUrl($w['agency'], $w['brand'], $up->json('data.id')))->assertNoContent();
    }

    $staff = $this->actingAs($w['staff'], 'web');
    $staff->postJson(bfUrl($w['agency'], $w['brand'], 'uploads/init'), ['mime_type' => 'application/pdf', 'declared_bytes' => 10, 'file_type' => 'brief'])->assertForbidden();
    $staff->postJson(bfUrl($w['agency'], $w['brand'], 'uploads/complete'), ['upload_id' => 'x', 'original_filename' => 'a.pdf', 'file_type' => 'brief'])->assertForbidden();
    $staff->deleteJson(bfUrl($w['agency'], $w['brand'], $file->ulid))->assertForbidden();
    $staff->getJson(bfUrl($w['agency'], $w['brand']))->assertOk();
    $staff->getJson(bfUrl($w['agency'], $w['brand'], $file->ulid))->assertOk();

    expect(BrandFile::query()->count())->toBe(1)
        ->and(Storage::disk('media')->exists($file->storage_key))->toBeTrue();
});

it('B7: policy unit: every action for admin, manager and staff, judged in the current agency', function (): void {
    $w = WaveFixtures::world();
    $policy = new BrandFilePolicy;
    $file = BrandFile::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
    $context = app(TenancyContext::class);

    $context->forget();
    expect($policy->viewAny($w['admin']))->toBeFalse();

    $context->setAgencyId($w['agency']->id);
    expect([$policy->viewAny($w['admin']), $policy->view($w['admin'], $file), $policy->create($w['admin']), $policy->delete($w['admin'], $file)])->toBe([true, true, true, true])
        ->and([$policy->viewAny($w['manager']), $policy->view($w['manager'], $file), $policy->create($w['manager']), $policy->delete($w['manager'], $file)])->toBe([true, true, true, true])
        ->and([$policy->viewAny($w['staff']), $policy->view($w['staff'], $file), $policy->create($w['staff']), $policy->delete($w['staff'], $file)])->toBe([true, true, false, false])
        ->and($policy->viewAny($w['otherAdmin']))->toBeFalse();
    $context->forget();
});

it('B9: init accepts jpeg, png, pdf, docx, xlsx and zip (both zip types) and refuses exe, svg and mp4', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $actor = fn () => $this->actingAs($w['admin'], 'web');

    foreach ([
        'image/jpeg', 'image/png', 'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip', 'application/x-zip-compressed',
    ] as $mime) {
        $actor()->postJson(bfUrl($w['agency'], $w['brand'], 'uploads/init'), ['mime_type' => $mime, 'declared_bytes' => 100, 'file_type' => 'assets'])->assertOk();
    }

    foreach (['application/x-msdownload', 'image/svg+xml', 'video/mp4', 'text/html'] as $mime) {
        $actor()->postJson(bfUrl($w['agency'], $w['brand'], 'uploads/init'), ['mime_type' => $mime, 'declared_bytes' => 100, 'file_type' => 'assets'])
            ->assertUnprocessable()->assertJsonPath('errors.0.code', 'brand_file.presign_failed');
    }
});

it('B9: declared_bytes 0 and over 100 MB fail; exactly 100 MB passes', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $init = fn (int $bytes) => $this->actingAs($w['admin'], 'web')
        ->postJson(bfUrl($w['agency'], $w['brand'], 'uploads/init'), ['mime_type' => 'application/zip', 'declared_bytes' => $bytes, 'file_type' => 'assets']);

    $init(0)->assertUnprocessable();
    $init(100 * 1024 * 1024 + 1)->assertUnprocessable()->assertJsonPath('errors.0.code', 'brand_file.presign_failed');
    $init(100 * 1024 * 1024)->assertOk()->assertJsonPath('data.max_bytes', 100 * 1024 * 1024);
});

it('B9: an invalid or missing file_type fails on init and on complete', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();

    $this->actingAs($w['admin'], 'web')->postJson(bfUrl($w['agency'], $w['brand'], 'uploads/init'), ['mime_type' => 'application/pdf', 'declared_bytes' => 10, 'file_type' => 'secret'])
        ->assertUnprocessable()->assertJsonPath('errors.0.meta.field', 'file_type');
    $this->actingAs($w['admin'], 'web')->postJson(bfUrl($w['agency'], $w['brand'], 'uploads/init'), ['mime_type' => 'application/pdf', 'declared_bytes' => 10])
        ->assertUnprocessable()->assertJsonPath('errors.0.meta.field', 'file_type');
    $this->actingAs($w['admin'], 'web')->postJson(bfUrl($w['agency'], $w['brand'], 'uploads/complete'), ['upload_id' => 'x', 'original_filename' => 'a.pdf', 'file_type' => 'secret'])
        ->assertUnprocessable()->assertJsonPath('errors.0.meta.field', 'file_type');
});

it('B9: complete refuses a missing object, an empty object, an over-cap object and a wrong extension', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $prefix = BrandFileUploadService::prefix($w['agency'], $w['brand']);
    $complete = fn (string $id) => $this->actingAs($w['admin'], 'web')
        ->postJson(bfUrl($w['agency'], $w['brand'], 'uploads/complete'), ['upload_id' => $id, 'original_filename' => 'a.pdf', 'file_type' => 'brief']);

    $complete($prefix.'never-landed.pdf')->assertUnprocessable()->assertJsonPath('errors.0.code', 'brand_file.complete_failed');

    Storage::disk('media')->put($prefix.'empty.pdf', '');
    $complete($prefix.'empty.pdf')->assertUnprocessable()->assertJsonPath('errors.0.code', 'brand_file.complete_failed');

    // A sparse file one byte over the cap: no 100 MB string is ever held in memory.
    Storage::disk('media')->put($prefix.'huge.zip', 'x');
    $hugePath = Storage::disk('media')->path($prefix.'huge.zip');
    expect(bfTruncateTo($hugePath, 100 * 1024 * 1024 + 1))->toBeTrue();
    $complete($prefix.'huge.zip')->assertUnprocessable()->assertJsonPath('errors.0.code', 'brand_file.complete_failed');

    Storage::disk('media')->put($prefix.'malware.exe', 'x');
    $complete($prefix.'malware.exe')->assertUnprocessable()->assertJsonPath('errors.0.code', 'brand_file.complete_failed');

    expect(BrandFile::query()->count())->toBe(0);
});

it('B9: the MIME stored on the row comes from the key extension, not from anything the client said', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $init = $this->actingAs($w['admin'], 'web')
        ->postJson(bfUrl($w['agency'], $w['brand'], 'uploads/init'), ['mime_type' => 'application/zip', 'declared_bytes' => 100, 'file_type' => 'assets'])
        ->assertOk()->json('data');
    Storage::disk('media')->put($init['upload_id'], str_repeat('a', 100));

    $res = $this->actingAs($w['admin'], 'web')->postJson(bfUrl($w['agency'], $w['brand'], 'uploads/complete'), [
        'upload_id' => $init['upload_id'], 'original_filename' => 'photo.png', 'file_type' => 'assets', 'mime_type' => 'image/png',
    ])->assertCreated();

    expect($res->json('data.attributes.mime_type'))->toBe('application/zip')
        ->and(BrandFile::query()->firstOrFail()->mime_type)->toBe('application/zip');
});

it('B9: the signed download URL forces Content-Disposition: attachment with a sanitized filename', function (): void {
    // No Storage::fake: the phpunit.xml media disk is a real AwsS3V3Adapter; presigning is local computation.
    Storage::purge('media');
    app()->forgetInstance('filesystem');
    expect(Storage::disk('media'))->toBeInstanceOf(AwsS3V3Adapter::class);

    $file = BrandFile::factory()->create(['original_filename' => 'Q3 Brand Guide (final)!.pdf']);
    $url = app(BrandFileUploadService::class)->signedDownloadUrl($file);

    expect($url)->not->toBeNull();
    $query = [];
    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

    expect($query['response-content-disposition'])->toBe('attachment; filename="Q3-Brand-Guide-final.pdf"');
});

it('B9: a failing object delete returns 500 brand_file.delete_failed and keeps the row, and audits nothing', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $file = BrandFile::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();

    $failing = Mockery::mock(Filesystem::class);
    $failing->shouldReceive('delete')->andReturn(false);
    Storage::set('media', $failing);

    $this->actingAs($w['admin'], 'web')->deleteJson(bfUrl($w['agency'], $w['brand'], $file->ulid))
        ->assertStatus(500)->assertJsonPath('errors.0.code', 'brand_file.delete_failed');

    expect(BrandFile::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'brand_file.deleted')->count())->toBe(0);
});

it('B9: an archived brand blocks upload and delete (422 brand_file.brand_archived) but not list or download', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $file = BrandFile::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
    Storage::disk('media')->put($file->storage_key, 'x');
    $w['brand']->update(['status' => BrandStatus::Archived]);
    $w['brand']->delete();
    $actor = fn () => $this->actingAs($w['admin'], 'web');

    $actor()->postJson(bfUrl($w['agency'], $w['brand'], 'uploads/init'), ['mime_type' => 'application/pdf', 'declared_bytes' => 10, 'file_type' => 'brief'])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'brand_file.brand_archived');
    $actor()->postJson(bfUrl($w['agency'], $w['brand'], 'uploads/complete'), ['upload_id' => $file->storage_key, 'original_filename' => 'a.pdf', 'file_type' => 'brief'])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'brand_file.brand_archived');
    $actor()->deleteJson(bfUrl($w['agency'], $w['brand'], $file->ulid))
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'brand_file.brand_archived');
    $actor()->getJson(bfUrl($w['agency'], $w['brand']))->assertOk()->assertJsonPath('meta.total', 1);
    $actor()->getJson(bfUrl($w['agency'], $w['brand'], $file->ulid))->assertOk();

    expect(Storage::disk('media')->exists($file->storage_key))->toBeTrue();
});

it('B10: audits brand_file.uploaded and brand_file.deleted with actor and agency, and nothing on a refused request', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $post = fn (string $url, array $body): TestResponse => $this->actingAs($w['admin'], 'web')->postJson($url, $body);

    $id = bfUpload($post, $w['agency'], $w['brand'])->assertCreated()->json('data.id');
    $this->actingAs($w['admin'], 'web')->deleteJson(bfUrl($w['agency'], $w['brand'], $id))->assertNoContent();

    $up = AuditLog::query()->where('action', 'brand_file.uploaded')->firstOrFail();
    $del = AuditLog::query()->where('action', 'brand_file.deleted')->firstOrFail();
    expect($up->actor_id)->toBe($w['admin']->id)
        ->and($up->agency_id)->toBe($w['agency']->id)
        ->and($del->actor_id)->toBe($w['admin']->id);

    // The payloads carry the decided literal keys and nothing else: no storage_key, no internal id.
    $keys = ['file_type', 'mime_type', 'original_filename', 'size_bytes'];
    expect(array_keys($up->after ?? []))->toEqualCanonicalizing($keys)
        ->and(array_keys($del->before ?? []))->toEqualCanonicalizing($keys);

    $this->actingAs($w['staff'], 'web')->postJson(bfUrl($w['agency'], $w['brand'], 'uploads/init'), ['mime_type' => 'application/pdf', 'declared_bytes' => 10, 'file_type' => 'brief'])->assertForbidden();
    expect(AuditLog::query()->where('action', 'brand_file.uploaded')->count())->toBe(1);
});

it('B12: the resource shape carries ulids only: no storage_key, no internal ids', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $file = BrandFile::factory()->forBrand($w['agency']->id, $w['brand']->id)->create(['uploaded_by_user_id' => $w['admin']->id]);

    $json = $this->actingAs($w['admin'], 'web')->getJson(bfUrl($w['agency'], $w['brand'], $file->ulid))->assertOk()->json('data');

    expect($json['id'])->toBe($file->ulid)
        ->and($json['attributes'])->toHaveKeys(['original_filename', 'mime_type', 'size_bytes', 'file_type', 'download_url', 'created_at'])
        ->and($json['attributes'])->not->toHaveKeys(['storage_key', 'agency_id', 'brand_id', 'uploaded_by_user_id'])
        ->and($json['relationships']['uploaded_by']['data']['id'])->toBe($w['admin']->ulid)
        ->and(json_encode($json))->not->toContain($file->storage_key);
});

it('F1: confirming the same upload_id twice is 422 brand_file.complete_failed: one row, one audit row, the object kept', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $post = fn (string $url, array $body): TestResponse => $this->actingAs($w['admin'], 'web')->postJson($url, $body);

    $init = $post(bfUrl($w['agency'], $w['brand'], 'uploads/init'), ['mime_type' => 'application/pdf', 'declared_bytes' => 64, 'file_type' => 'brief'])
        ->assertOk()->json('data');
    Storage::disk('media')->put($init['upload_id'], str_repeat('a', 64));
    $body = ['upload_id' => $init['upload_id'], 'original_filename' => 'brief.pdf', 'file_type' => 'brief'];

    $post(bfUrl($w['agency'], $w['brand'], 'uploads/complete'), $body)->assertCreated();
    $post(bfUrl($w['agency'], $w['brand'], 'uploads/complete'), $body)
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'brand_file.complete_failed');

    expect(BrandFile::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'brand_file.uploaded')->count())->toBe(1)
        ->and(Storage::disk('media')->exists($init['upload_id']))->toBeTrue();
});
