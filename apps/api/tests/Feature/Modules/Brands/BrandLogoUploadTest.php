<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Services\BrandLogoUploadService;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-053 D7 — brand logo upload (the avatar pattern: direct multipart,
 * server-side re-encode, private disk, signed emission).
 *
 * The security-relevant pins are cross-tenant isolation, content-based MIME
 * rejection, EXIF stripping and signed-URL-only emission.
 */

/** @return array{agency: Agency, admin: User, brand: Brand} */
function logoFixture(): array
{
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->missingFloorField('logo_path')->createOne();

    return compact('agency', 'admin', 'brand');
}

function logoUrl(Agency $agency, Brand $brand): string
{
    return "/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}/logo";
}

// NOTE: every multipart POST below carries an explicit JSON Accept header.
// Without it Laravel answers a validation failure with a 302 redirect (the
// HTML form flow) rather than the API's 422 envelope.

/** A real PNG the GD driver can decode. */
function fakeLogo(string $name = 'logo.png'): UploadedFile
{
    return UploadedFile::fake()->image($name, 256, 256);
}

/**
 * A PHP script on disk named `.png`. Built as a REAL UploadedFile rather than
 * `UploadedFile::fake()`, because the fake reports a MIME guessed from the
 * filename — which would defeat the very check under test.
 */
function disguisedScriptUpload(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'logo').'.png';
    file_put_contents($path, "<?php echo 'pwned';");

    return new UploadedFile($path, 'logo.png', 'image/png', null, true);
}

/** A JPEG carrying a unique ASCII marker inside an APP1/EXIF segment. */
function jpegLogoWithExifMarker(string $marker): string
{
    $image = imagecreatetruecolor(64, 64);
    assert($image !== false);
    ob_start();
    imagejpeg($image, null, 90);
    $jpeg = (string) ob_get_clean();
    imagedestroy($image);

    $payload = "Exif\x00\x00".$marker;
    $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

    return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
}

// ── Happy path ──────────────────────────────────────────────────────────────

it('uploads a logo to an agency- and brand-scoped path and returns the refreshed brand', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = logoFixture();

    $response = $this->actingAs($admin)
        ->post(logoUrl($agency, $brand), ['file' => fakeLogo()], ['Accept' => 'application/json'])
        ->assertOk();

    $path = $brand->fresh()?->logo_path;

    expect($path)->toMatch(
        "#^agencies/{$agency->ulid}/brands/{$brand->ulid}/logo/[0-9A-Z]{26}\.png$#",
    );
    Storage::disk('media')->assertExists((string) $path);

    // The client never has to re-fetch to render the new logo.
    expect($response->json('data.attributes.logo_path'))->toBe($path)
        ->and($response->json('data.attributes.logo_url'))->toBeString();
});

it('replaces in place — a second upload repoints the column to a new key', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = logoFixture();

    $this->actingAs($admin)->post(logoUrl($agency, $brand), ['file' => fakeLogo('first.png')], ['Accept' => 'application/json'])->assertOk();
    $first = $brand->fresh()?->logo_path;

    $this->actingAs($admin)->post(logoUrl($agency, $brand), ['file' => fakeLogo('second.png')], ['Accept' => 'application/json'])->assertOk();
    $second = $brand->fresh()?->logo_path;

    expect($second)->not->toBe($first);

    // Mirrors the avatar precedent: replacement does not delete the previous
    // object. Exactly one code path (the explicit remove) can destroy a file.
    Storage::disk('media')->assertExists((string) $first);
    Storage::disk('media')->assertExists((string) $second);
});

it('removes the logo and its stored object', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = logoFixture();

    $this->actingAs($admin)->post(logoUrl($agency, $brand), ['file' => fakeLogo()], ['Accept' => 'application/json'])->assertOk();
    $path = (string) $brand->fresh()?->logo_path;

    $this->actingAs($admin)->deleteJson(logoUrl($agency, $brand))->assertOk();

    expect($brand->fresh()?->logo_path)->toBeNull();
    Storage::disk('media')->assertMissing($path);
});

it('deletes ONLY this brand logo — the remove action has no reach beyond its own object (§5.34)', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = logoFixture();
    $sibling = Brand::factory()->forAgency($agency->id)->missingFloorField('logo_path')->createOne();

    $this->actingAs($admin)->post(logoUrl($agency, $brand), ['file' => fakeLogo()], ['Accept' => 'application/json'])->assertOk();
    $this->actingAs($admin)->post(logoUrl($agency, $sibling), ['file' => fakeLogo()], ['Accept' => 'application/json'])->assertOk();

    $siblingPath = (string) $sibling->fresh()?->logo_path;
    $unrelated = 'creators/01UNRELATEDXXXXXXXXXXXXXXX/avatar/keep.png';
    Storage::disk('media')->put($unrelated, 'keep me');

    $this->actingAs($admin)->deleteJson(logoUrl($agency, $brand))->assertOk();

    expect($sibling->fresh()?->logo_path)->toBe($siblingPath);
    Storage::disk('media')->assertExists($siblingPath);
    Storage::disk('media')->assertExists($unrelated);
});

// ── Content validation ──────────────────────────────────────────────────────

it('rejects a script disguised as an image by CONTENT, not extension', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = logoFixture();

    $this->actingAs($admin)
        ->post(logoUrl($agency, $brand), ['file' => disguisedScriptUpload()], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    expect($brand->fresh()?->logo_path)->toBeNull();
    Storage::disk('media')->assertDirectoryEmpty('agencies');
});

it('rejects the disguised script at the SERVICE layer too — the check is content, not the request rule', function (): void {
    // The endpoint rejects this twice over (Laravel's `mimes:` rule, then the
    // service). Pinning the service directly proves the magic-byte guard is
    // doing the work, so relaxing the request rule can never silently open the
    // path.
    $service = new BrandLogoUploadService;

    expect(fn (): string => $service->resolveExtension(disguisedScriptUpload()))
        ->toThrow(RuntimeException::class);
});

it('rejects an undecodable file that claims a supported type (422, not a 500)', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = logoFixture();

    // A real PNG header followed by garbage: it sniffs as image/png but no
    // decoder can read it. Intervention throws its own exception hierarchy,
    // which the service translates rather than letting it become a 500.
    $path = tempnam(sys_get_temp_dir(), 'logo').'.png';
    file_put_contents($path, "\x89PNG\r\n\x1a\n".str_repeat("\x00", 64));

    $this->actingAs($admin)
        ->post(
            logoUrl($agency, $brand),
            ['file' => new UploadedFile($path, 'logo.png', 'image/png', null, true)],
            ['Accept' => 'application/json'],
        )
        ->assertUnprocessable();

    expect($brand->fresh()?->logo_path)->toBeNull();
});

it('rejects a disallowed image type (gif is outside the 3-MIME allowlist)', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = logoFixture();

    $this->actingAs($admin)
        ->post(logoUrl($agency, $brand), ['file' => UploadedFile::fake()->image('logo.gif', 64, 64)], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    expect($brand->fresh()?->logo_path)->toBeNull();
});

it('rejects a file above the configured cap', function (): void {
    Storage::fake('media');
    config(['uploads.brand_logo_max_bytes' => 1024]);
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = logoFixture();

    $big = UploadedFile::fake()->image('logo.png', 512, 512)->size(64);

    $this->actingAs($admin)
        ->post(logoUrl($agency, $brand), ['file' => $big], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    expect($brand->fresh()?->logo_path)->toBeNull();
});

it('strips EXIF by re-encoding from the decoded pixels', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = logoFixture();

    $marker = 'CATALYST-BRAND-EXIF-MARKER';
    $file = UploadedFile::fake()->createWithContent('logo.jpg', jpegLogoWithExifMarker($marker));

    $this->actingAs($admin)->post(logoUrl($agency, $brand), ['file' => $file], ['Accept' => 'application/json'])->assertOk();

    $stored = Storage::disk('media')->get((string) $brand->fresh()?->logo_path);

    expect($stored)->not->toContain($marker);
});

// ── Tenancy + authorization ─────────────────────────────────────────────────

it('cannot upload a logo to another agency brand (404, non-fingerprinting)', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'admin' => $admin] = logoFixture();

    $otherAgency = Agency::factory()->createOne();
    $victim = Brand::factory()->forAgency($otherAgency->id)->missingFloorField('logo_path')->createOne();

    // Through the attacker's own agency path — the brand simply is not theirs.
    $this->actingAs($admin)
        ->post("/api/v1/agencies/{$agency->ulid}/brands/{$victim->ulid}/logo", ['file' => fakeLogo()], ['Accept' => 'application/json'])
        ->assertNotFound();

    // And through the victim's agency path — membership is missing.
    $this->actingAs($admin)
        ->post(logoUrl($otherAgency, $victim), ['file' => fakeLogo()], ['Accept' => 'application/json'])
        ->assertNotFound();

    expect($victim->fresh()?->logo_path)->toBeNull();
});

it('cannot replace or delete another agency brand logo', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'admin' => $admin] = logoFixture();

    $otherAgency = Agency::factory()->createOne();
    $otherAdmin = User::factory()->agencyAdmin($otherAgency)->createOne();
    $victim = Brand::factory()->forAgency($otherAgency->id)->missingFloorField('logo_path')->createOne();

    $this->actingAs($otherAdmin)->post(logoUrl($otherAgency, $victim), ['file' => fakeLogo()], ['Accept' => 'application/json'])->assertOk();
    $victimPath = (string) $victim->fresh()?->logo_path;

    $this->actingAs($admin)
        ->post("/api/v1/agencies/{$agency->ulid}/brands/{$victim->ulid}/logo", ['file' => fakeLogo()], ['Accept' => 'application/json'])
        ->assertNotFound();
    $this->actingAs($admin)
        ->deleteJson("/api/v1/agencies/{$agency->ulid}/brands/{$victim->ulid}/logo")
        ->assertNotFound();

    expect($victim->fresh()?->logo_path)->toBe($victimPath);
    Storage::disk('media')->assertExists($victimPath);
});

it('lets a manager manage the logo but refuses staff (the brand update posture)', function (): void {
    Storage::fake('media');
    $agency = Agency::factory()->createOne();
    $manager = User::factory()->agencyManager($agency)->createOne();
    $staff = User::factory()->agencyStaff($agency)->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->missingFloorField('logo_path')->createOne();

    $this->actingAs($staff)
        ->post(logoUrl($agency, $brand), ['file' => fakeLogo()], ['Accept' => 'application/json'])
        ->assertForbidden();

    $this->actingAs($manager)
        ->post(logoUrl($agency, $brand), ['file' => fakeLogo()], ['Accept' => 'application/json'])
        ->assertOk();

    $this->actingAs($staff)->deleteJson(logoUrl($agency, $brand))->assertForbidden();
});

it('refuses an unauthenticated upload', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'brand' => $brand] = logoFixture();

    $this->postJson(logoUrl($agency, $brand), [])->assertUnauthorized();
});

// ── Emission ────────────────────────────────────────────────────────────────

it('emits logo_url as a signed URL and null when there is no logo', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = logoFixture();

    $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}")
        ->assertOk()
        ->assertJsonPath('data.attributes.logo_url', null)
        ->assertJsonPath('data.attributes.logo_path', null);

    $this->actingAs($admin)->post(logoUrl($agency, $brand), ['file' => fakeLogo()], ['Accept' => 'application/json'])->assertOk();

    $url = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}")
        ->assertOk()
        ->json('data.attributes.logo_url');

    // A URL, not the raw key: the disk is private, so the key alone is useless.
    expect($url)->toBeString()
        ->and($url)->not->toBe($brand->fresh()?->logo_path);
});

it('records the logo change in the brand audit trail', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = logoFixture();

    $this->actingAs($admin)->post(logoUrl($agency, $brand), ['file' => fakeLogo()], ['Accept' => 'application/json'])->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'brand.updated',
        'subject_id' => $brand->id,
    ]);
});

// ── Floor interaction (D6/D7) ───────────────────────────────────────────────

it('unblocks the D6 edit gate once a logo is uploaded, and re-blocks when it is removed', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = logoFixture();
    $url = "/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}";

    $this->actingAs($admin)->patchJson($url, ['name' => 'Blocked'])->assertUnprocessable();

    $this->actingAs($admin)->post(logoUrl($agency, $brand), ['file' => fakeLogo()], ['Accept' => 'application/json'])->assertOk();
    $this->actingAs($admin)->patchJson($url, ['name' => 'Allowed'])->assertOk();

    // Removing a logo is always permitted — an agency must be able to take
    // down a mark it no longer has rights to. The NEXT content edit gates.
    $this->actingAs($admin)->deleteJson(logoUrl($agency, $brand))->assertOk();
    $this->actingAs($admin)
        ->patchJson($url, ['name' => 'Blocked again'])
        ->assertUnprocessable()
        ->assertEnvelopeValidationErrors(['logo_path']);
});

// ── Storage honesty ─────────────────────────────────────────────────────────

it('refuses to record a logo when the disk reports the write failed', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = logoFixture();

    // Every object-storage disk in this app is `'throw' => false`, so an
    // unreachable bucket makes `put()` return false rather than raise. Left
    // unchecked, the endpoint answers 200 and `logo_path` points at a key
    // with no object behind it — a brand whose logo silently 404s forever.
    $failing = Mockery::mock(Filesystem::class);
    $failing->shouldReceive('put')->once()->andReturnFalse();
    Storage::set('media', $failing);

    $this->actingAs($admin)
        ->post(logoUrl($agency, $brand), ['file' => fakeLogo()], ['Accept' => 'application/json'])
        ->assertStatus(500);

    // The transaction rolled back, so the column was never set — the brand is
    // exactly as incomplete as it was before the attempt.
    expect($brand->refresh()->logo_path)->toBeNull();
});
