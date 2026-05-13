<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Brands\Enums\BrandStatus;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** @return array{agency: Agency, user: User} */
function makeAdmin(): array
{
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyAdmin($agency)->createOne();

    return compact('agency', 'user');
}

/** @return array{agency: Agency, user: User} */
function makeManager(): array
{
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyManager($agency)->createOne();

    return compact('agency', 'user');
}

/** @return array{agency: Agency, user: User} */
function makeStaff(): array
{
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyStaff($agency)->createOne();

    return compact('agency', 'user');
}

// ---------------------------------------------------------------------------
// Index
// ---------------------------------------------------------------------------

it('agency_admin can list active brands', function (): void {
    ['agency' => $agency, 'user' => $user] = makeAdmin();
    Brand::factory()->count(3)->forAgency($agency->id)->create();
    Brand::factory()->archived()->forAgency($agency->id)->create();

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/brands")
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('agency_manager can list brands', function (): void {
    ['agency' => $agency, 'user' => $user] = makeManager();
    Brand::factory()->count(2)->forAgency($agency->id)->create();

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/brands")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('agency_staff can list brands', function (): void {
    ['agency' => $agency, 'user' => $user] = makeStaff();
    Brand::factory()->forAgency($agency->id)->create();

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/brands")
        ->assertOk();
});

it('archived brands are excluded from default list', function (): void {
    ['agency' => $agency, 'user' => $user] = makeAdmin();
    Brand::factory()->forAgency($agency->id)->create(['name' => 'Active']);
    Brand::factory()->archived()->forAgency($agency->id)->create(['name' => 'Archived']);

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/brands")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.name', 'Active');
});

it('returns archived brands when ?status=archived is passed', function (): void {
    ['agency' => $agency, 'user' => $user] = makeAdmin();
    Brand::factory()->forAgency($agency->id)->create();
    Brand::factory()->archived()->forAgency($agency->id)->create(['name' => 'Archived']);

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/brands?status=archived")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.name', 'Archived');
});

it('unauthenticated request returns 401', function (): void {
    $agency = Agency::factory()->createOne();

    $this->getJson("/api/v1/agencies/{$agency->ulid}/brands")
        ->assertUnauthorized();
});

it('cross-tenant list returns 404', function (): void {
    ['agency' => $agency, 'user' => $user] = makeAdmin();
    $otherAgency = Agency::factory()->createOne();
    Brand::factory()->forAgency($otherAgency->id)->create();

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$otherAgency->ulid}/brands")
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// Store
// ---------------------------------------------------------------------------

it('agency_admin can create a brand', function (): void {
    ['agency' => $agency, 'user' => $user] = makeAdmin();

    $payload = [
        'name' => 'Acme Corp',
        'slug' => 'acme-corp',
        'description' => 'A fine brand.',
    ];

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/brands", $payload)
        ->assertCreated()
        ->assertJsonPath('data.attributes.name', 'Acme Corp')
        ->assertJsonPath('data.attributes.slug', 'acme-corp');

    $this->assertDatabaseHas('brands', ['name' => 'Acme Corp', 'agency_id' => $agency->id]);
});

it('agency_manager can create a brand', function (): void {
    ['agency' => $agency, 'user' => $user] = makeManager();

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/brands", [
            'name' => 'Beta Brand',
            'slug' => 'beta-brand',
        ])
        ->assertCreated();
});

it('agency_staff cannot create a brand', function (): void {
    ['agency' => $agency, 'user' => $user] = makeStaff();

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/brands", [
            'name' => 'Gamma Brand',
            'slug' => 'gamma-brand',
        ])
        ->assertForbidden();
});

it('brand creation emits brand.created audit log', function (): void {
    ['agency' => $agency, 'user' => $user] = makeAdmin();

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/brands", [
            'name' => 'Audit Brand',
            'slug' => 'audit-brand',
        ])
        ->assertCreated();

    $this->assertDatabaseHas('audit_logs', [
        'action' => AuditAction::BrandCreated->value,
    ]);
});

it('brand creation validates required fields', function (): void {
    ['agency' => $agency, 'user' => $user] = makeAdmin();

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/brands", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'slug']);
});

it('brand slug must be unique within agency', function (): void {
    ['agency' => $agency, 'user' => $user] = makeAdmin();
    Brand::factory()->forAgency($agency->id)->create(['slug' => 'taken-slug']);

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/brands", [
            'name' => 'Dupe Brand',
            'slug' => 'taken-slug',
        ])
        ->assertUnprocessable();
});

it('same slug is allowed across different agencies', function (): void {
    ['agency' => $agency, 'user' => $user] = makeAdmin();
    $otherAgency = Agency::factory()->createOne();
    Brand::factory()->forAgency($otherAgency->id)->create(['slug' => 'shared-slug']);

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/brands", [
            'name' => 'OK Brand',
            'slug' => 'shared-slug',
        ])
        ->assertCreated();
});

// ---------------------------------------------------------------------------
// Show
// ---------------------------------------------------------------------------

it('any role can view a brand', function (): void {
    ['agency' => $agency, 'user' => $user] = makeStaff();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}")
        ->assertOk()
        ->assertJsonPath('data.id', $brand->ulid);
});

it('brand response does not expose integer id', function (): void {
    ['agency' => $agency, 'user' => $user] = makeAdmin();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    $response = $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}")
        ->assertOk()
        ->json();

    expect($response['data']['id'])->toBe($brand->ulid)
        ->and($response['data'])->not->toHaveKey('attributes.id');
});

it('cross-tenant brand show returns 404', function (): void {
    ['agency' => $agency, 'user' => $user] = makeAdmin();
    $otherAgency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($otherAgency->id)->createOne();

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}")
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// Update
// ---------------------------------------------------------------------------

it('agency_admin can update a brand', function (): void {
    ['agency' => $agency, 'user' => $user] = makeAdmin();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($user)
        ->patchJson("/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}", [
            'name' => 'Updated Name',
        ])
        ->assertOk()
        ->assertJsonPath('data.attributes.name', 'Updated Name');
});

it('agency_manager can update a brand', function (): void {
    ['agency' => $agency, 'user' => $user] = makeManager();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($user)
        ->patchJson("/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}", [
            'name' => 'Manager Updated',
        ])
        ->assertOk();
});

it('agency_staff cannot update a brand', function (): void {
    ['agency' => $agency, 'user' => $user] = makeStaff();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($user)
        ->patchJson("/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}", [
            'name' => 'Staff Updated',
        ])
        ->assertForbidden();
});

it('brand update emits brand.updated audit log', function (): void {
    ['agency' => $agency, 'user' => $user] = makeAdmin();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($user)
        ->patchJson("/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}", ['name' => 'New Name'])
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::BrandUpdated->value]);
});

it('update validates default_language against supported locales', function (): void {
    ['agency' => $agency, 'user' => $user] = makeAdmin();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($user)
        ->patchJson("/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}", [
            'default_language' => 'fr',
        ])
        ->assertUnprocessable();
});

// ---------------------------------------------------------------------------
// Archive (DELETE)
// ---------------------------------------------------------------------------

it('agency_admin can archive a brand', function (): void {
    ['agency' => $agency, 'user' => $user] = makeAdmin();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($user)
        ->deleteJson("/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}")
        ->assertOk()
        ->assertJsonPath('data.attributes.status', BrandStatus::Archived->value);

    $this->assertSoftDeleted('brands', ['id' => $brand->id]);
});

it('agency_manager can archive a brand', function (): void {
    ['agency' => $agency, 'user' => $user] = makeManager();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($user)
        ->deleteJson("/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}")
        ->assertOk()
        ->assertJsonPath('data.attributes.status', BrandStatus::Archived->value);
});

it('agency_staff cannot archive a brand', function (): void {
    ['agency' => $agency, 'user' => $user] = makeStaff();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($user)
        ->deleteJson("/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}")
        ->assertForbidden();
});

it('archive emits brand.archived audit log', function (): void {
    ['agency' => $agency, 'user' => $user] = makeAdmin();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($user)
        ->deleteJson("/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}")
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::BrandArchived->value]);
});

it('archived brand is excluded from default list', function (): void {
    ['agency' => $agency, 'user' => $user] = makeAdmin();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($user)
        ->deleteJson("/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}")
        ->assertOk();

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/brands")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
