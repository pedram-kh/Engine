<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Policies\BrandPolicy;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * BrandPolicy unit tests — exercise the policy methods directly, independent
 * of the HTTP layer / tenancy.agency middleware.
 *
 * Rationale: The empirical break-revert (Sprint 2 Chunk 1 review) showed that
 * breaking BrandPolicy::view() to return true unconditionally caused ZERO test
 * failures in the HTTP-level BrandCrudTest suite. This is because tenancy.agency
 * middleware blocks non-members at the route layer before the policy is evaluated.
 * These unit tests close that gap by calling the policy directly.
 */
$policy = new BrandPolicy;

// ---------------------------------------------------------------------------
// viewAny + view — all roles can view; non-member cannot
// ---------------------------------------------------------------------------

it('viewAny returns true for agency_admin', function () use ($policy): void {
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyAdmin($agency)->createOne();

    expect($policy->viewAny($user))->toBeTrue();
});

it('viewAny returns true for agency_manager', function () use ($policy): void {
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyManager($agency)->createOne();

    expect($policy->viewAny($user))->toBeTrue();
});

it('viewAny returns true for agency_staff', function () use ($policy): void {
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyStaff($agency)->createOne();

    expect($policy->viewAny($user))->toBeTrue();
});

it('viewAny returns false for a user with no membership anywhere', function () use ($policy): void {
    $user = User::factory()->createOne();

    expect($policy->viewAny($user))->toBeFalse();
});

it('view returns true for any member', function () use ($policy): void {
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyStaff($agency)->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    expect($policy->view($user, $brand))->toBeTrue();
});

it('view returns false for a user with no membership', function () use ($policy): void {
    $user = User::factory()->createOne();
    $brand = Brand::factory()->createOne();

    expect($policy->view($user, $brand))->toBeFalse();
});

// ---------------------------------------------------------------------------
// create — admin + manager; not staff
// ---------------------------------------------------------------------------

it('create returns true for agency_admin', function () use ($policy): void {
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyAdmin($agency)->createOne();

    expect($policy->create($user))->toBeTrue();
});

it('create returns true for agency_manager', function () use ($policy): void {
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyManager($agency)->createOne();

    expect($policy->create($user))->toBeTrue();
});

it('create returns false for agency_staff', function () use ($policy): void {
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyStaff($agency)->createOne();

    expect($policy->create($user))->toBeFalse();
});

it('create returns false for a user with no membership', function () use ($policy): void {
    $user = User::factory()->createOne();

    expect($policy->create($user))->toBeFalse();
});

// ---------------------------------------------------------------------------
// update — admin + manager; not staff
// ---------------------------------------------------------------------------

it('update returns false for agency_staff', function () use ($policy): void {
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyStaff($agency)->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    expect($policy->update($user, $brand))->toBeFalse();
});

// ---------------------------------------------------------------------------
// archive — admin + manager; not staff
// ---------------------------------------------------------------------------

it('archive returns true for agency_admin', function () use ($policy): void {
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyAdmin($agency)->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    expect($policy->archive($user, $brand))->toBeTrue();
});

it('archive returns true for agency_manager', function () use ($policy): void {
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyManager($agency)->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    expect($policy->archive($user, $brand))->toBeTrue();
});

it('archive returns false for agency_staff', function () use ($policy): void {
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyStaff($agency)->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    expect($policy->archive($user, $brand))->toBeFalse();
});

// ---------------------------------------------------------------------------
// delete — admin only
// ---------------------------------------------------------------------------

it('delete returns true for agency_admin', function () use ($policy): void {
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyAdmin($agency)->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    expect($policy->delete($user, $brand))->toBeTrue();
});

it('delete returns false for agency_manager', function () use ($policy): void {
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyManager($agency)->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    expect($policy->delete($user, $brand))->toBeFalse();
});

it('delete returns false for agency_staff', function () use ($policy): void {
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyStaff($agency)->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    expect($policy->delete($user, $brand))->toBeFalse();
});
