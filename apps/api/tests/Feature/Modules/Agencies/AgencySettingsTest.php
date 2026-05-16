<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// GET settings
// ---------------------------------------------------------------------------

it('agency_admin can view agency settings', function (): void {
    $agency = Agency::factory()->createOne([
        'default_currency' => 'USD',
        'default_language' => 'en',
    ]);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/settings")
        ->assertOk()
        ->assertJsonPath('data.attributes.default_currency', 'USD')
        ->assertJsonPath('data.attributes.default_language', 'en');
});

it('agency_manager can view settings', function (): void {
    $agency = Agency::factory()->createOne();
    $manager = User::factory()->agencyManager($agency)->createOne();

    $this->actingAs($manager)
        ->getJson("/api/v1/agencies/{$agency->ulid}/settings")
        ->assertOk();
});

it('agency_staff can view settings', function (): void {
    $agency = Agency::factory()->createOne();
    $staff = User::factory()->agencyStaff($agency)->createOne();

    $this->actingAs($staff)
        ->getJson("/api/v1/agencies/{$agency->ulid}/settings")
        ->assertOk();
});

it('unauthenticated request returns 401', function (): void {
    $agency = Agency::factory()->createOne();

    $this->getJson("/api/v1/agencies/{$agency->ulid}/settings")
        ->assertUnauthorized();
});

it('cross-tenant settings access returns 404', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $otherAgency = Agency::factory()->createOne();

    $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$otherAgency->ulid}/settings")
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// PATCH settings
// ---------------------------------------------------------------------------

it('agency_admin can update default_currency', function (): void {
    $agency = Agency::factory()->createOne(['default_currency' => 'EUR']);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->patchJson("/api/v1/agencies/{$agency->ulid}/settings", [
            'default_currency' => 'GBP',
        ])
        ->assertOk()
        ->assertJsonPath('data.attributes.default_currency', 'GBP');

    $this->assertDatabaseHas('agencies', [
        'id' => $agency->id,
        'default_currency' => 'GBP',
    ]);
});

it('agency_admin can update default_language', function (): void {
    $agency = Agency::factory()->createOne(['default_language' => 'en']);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->patchJson("/api/v1/agencies/{$agency->ulid}/settings", [
            'default_language' => 'pt',
        ])
        ->assertOk()
        ->assertJsonPath('data.attributes.default_language', 'pt');
});

it('agency_manager cannot update settings', function (): void {
    $agency = Agency::factory()->createOne();
    $manager = User::factory()->agencyManager($agency)->createOne();

    $this->actingAs($manager)
        ->patchJson("/api/v1/agencies/{$agency->ulid}/settings", [
            'default_currency' => 'USD',
        ])
        ->assertForbidden();
});

it('agency_staff cannot update settings', function (): void {
    $agency = Agency::factory()->createOne();
    $staff = User::factory()->agencyStaff($agency)->createOne();

    $this->actingAs($staff)
        ->patchJson("/api/v1/agencies/{$agency->ulid}/settings", [
            'default_currency' => 'USD',
        ])
        ->assertForbidden();
});

it('settings update emits agency_settings.updated audit log', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->patchJson("/api/v1/agencies/{$agency->ulid}/settings", [
            'default_currency' => 'CHF',
        ])
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'action' => AuditAction::AgencySettingsUpdated->value,
    ]);
});

it('validates default_language against supported locales', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->patchJson("/api/v1/agencies/{$agency->ulid}/settings", [
            'default_language' => 'fr',
        ])
        ->assertEnvelopeValidationErrors(['default_language']);
});

it('validates default_currency must be 3 characters', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->patchJson("/api/v1/agencies/{$agency->ulid}/settings", [
            'default_currency' => 'EU', // too short
        ])
        ->assertEnvelopeValidationErrors(['default_currency']);
});
