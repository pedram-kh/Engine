<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyFile;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Campaign attachments from the Files library, chunk B (AH-092), S3 — the
 * Attachments tab's attach picker. `?for=select` on the EXISTING
 * `AgencyFileController::index` (the BrandController/AH-085 precedent,
 * the plan's "fourth invocation" of this decision procedure): every
 * matching file, unpaginated, in the thin
 * `{id, original_filename, mime_type, size_bytes}` projection. No
 * "already attached" filter — the picker dialog cross-references the
 * campaign's own already-fetched attachment list client-side.
 */
function makeFilesSelectAdmin(): array
{
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyAdmin($agency)->createOne();

    return [$agency, $user];
}

it('returns every file, unpaginated, past the general index\'s page boundary', function (): void {
    [$agency, $user] = makeFilesSelectAdmin();
    AgencyFile::factory()->count(30)->forAgency($agency->id)->create();

    $response = $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/files?for=select")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(30);
    expect($response->json('meta'))->toBeNull();
    expect($response->json('links'))->toBeNull();
});

it('the thin projection carries only {id, type, attributes: {original_filename, mime_type, size_bytes}} — no download_url, no category', function (): void {
    [$agency, $user] = makeFilesSelectAdmin();
    AgencyFile::factory()->forAgency($agency->id)->create([
        'original_filename' => 'brief.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 2048,
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/files?for=select")
        ->assertOk();

    $row = $response->json('data.0');

    expect(array_keys($row))->toBe(['id', 'type', 'attributes'])
        ->and(array_keys($row['attributes']))->toBe(['original_filename', 'mime_type', 'size_bytes'])
        ->and($row['attributes']['original_filename'])->toBe('brief.pdf')
        ->and($row['attributes']['mime_type'])->toBe('application/pdf')
        ->and($row['attributes']['size_bytes'])->toBe(2048)
        ->and($row['type'])->toBe('agency_files');
});

it('is ordered by original_filename, unlike the general index\'s newest-first order', function (): void {
    [$agency, $user] = makeFilesSelectAdmin();
    AgencyFile::factory()->forAgency($agency->id)->create(['original_filename' => 'zebra.pdf']);
    AgencyFile::factory()->forAgency($agency->id)->create(['original_filename' => 'acme.pdf']);
    AgencyFile::factory()->forAgency($agency->id)->create(['original_filename' => 'midway.pdf']);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/files?for=select")
        ->assertOk();

    /** @var array<int, array{attributes: array{original_filename: string}}> $rows */
    $rows = $response->json('data');
    $names = array_map(static fn (array $row): string => $row['attributes']['original_filename'], $rows);

    expect($names)->toBe(['acme.pdf', 'midway.pdf', 'zebra.pdf']);
});

it('never leaks another agency\'s files through ?for=select', function (): void {
    [$agency, $user] = makeFilesSelectAdmin();
    $otherAgency = Agency::factory()->createOne();
    AgencyFile::factory()->forAgency($agency->id)->create(['original_filename' => 'mine.pdf']);
    AgencyFile::factory()->forAgency($otherAgency->id)->create(['original_filename' => 'not-mine.pdf']);

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/files?for=select")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.original_filename', 'mine.pdf');
});

it('a staff member (any membership) can use ?for=select — same viewAny gate as the general index', function (): void {
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyStaff($agency)->createOne();
    AgencyFile::factory()->forAgency($agency->id)->create();

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/files?for=select")
        ->assertOk();
});

it('unauthenticated ?for=select returns 401, same as the general index', function (): void {
    $agency = Agency::factory()->createOne();

    $this->getJson("/api/v1/agencies/{$agency->ulid}/files?for=select")
        ->assertUnauthorized();
});

it('BACKWARD COMPAT — omitting ?for=select still paginates newest-first, unchanged', function (): void {
    [$agency, $user] = makeFilesSelectAdmin();
    AgencyFile::factory()->count(30)->forAgency($agency->id)->create();

    $response = $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/files")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(25)
        ->and($response->json('meta.total'))->toBe(30)
        ->and($response->json('meta.per_page'))->toBe(25)
        ->and($response->json('meta.last_page'))->toBe(2);
    // The full AgencyFileResource shape, not the thin projection.
    expect($response->json('data.0'))->toHaveKey('attributes.download_url');
});
