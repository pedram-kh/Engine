<?php

declare(strict_types=1);

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Database\Factories\AgencyMembershipFactory;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Identity\Models\User;
use App\Modules\TrackedJobs\Database\Factories\TrackedJobFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('returns the job when the authenticated user is the initiator', function (): void {
    $user = User::factory()->create();

    $job = TrackedJobFactory::new()->processing()->createOne([
        'kind' => 'bulk_creator_invitation',
        'initiator_user_id' => $user->id,
        'progress' => 0.5,
    ]);

    $response = $this->actingAs($user)->getJson("/api/v1/jobs/{$job->ulid}");

    $response->assertOk()
        ->assertJsonPath('data.id', $job->ulid)
        ->assertJsonPath('data.type', 'bulk_creator_invitation')
        ->assertJsonPath('data.status', 'processing')
        ->assertJsonPath('data.progress', 0.5);
});

it('returns the job when the authenticated user is a member of the agency', function (): void {
    $user = User::factory()->create();
    $agency = AgencyFactory::new()->createOne();

    AgencyMembershipFactory::new()->createOne([
        'user_id' => $user->id,
        'agency_id' => $agency->id,
        'role' => AgencyRole::AgencyAdmin,
        'accepted_at' => now(),
    ]);

    $job = TrackedJobFactory::new()->processing()->createOne([
        'kind' => 'bulk_creator_invitation',
        'agency_id' => $agency->id,
        'initiator_user_id' => User::factory()->create()->id,
    ]);

    $this->actingAs($user)->getJson("/api/v1/jobs/{$job->ulid}")
        ->assertOk()
        ->assertJsonPath('data.id', $job->ulid);
});

it('returns 404 when the user is neither initiator nor agency member (#42 user-enumeration defence)', function (): void {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $job = TrackedJobFactory::new()->processing()->createOne([
        'kind' => 'data_export',
        'initiator_user_id' => $owner->id,
    ]);

    $this->actingAs($stranger)->getJson("/api/v1/jobs/{$job->ulid}")
        ->assertNotFound()
        ->assertJsonPath('errors.0.code', 'job.not_found');
});

it('returns 404 when the job ulid is unknown', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/jobs/01HXXXXXXXXXXXXXXXXXXXXXXX')
        ->assertNotFound()
        ->assertJsonPath('errors.0.code', 'job.not_found');
});

it('requires authentication', function (): void {
    $job = TrackedJobFactory::new()->createOne();

    $this->getJson("/api/v1/jobs/{$job->ulid}")->assertUnauthorized();
});

it('estimated_completion_at is rendered as null when not set (D-pause-8)', function (): void {
    $user = User::factory()->create();
    $job = TrackedJobFactory::new()->createOne([
        'initiator_user_id' => $user->id,
        'estimated_completion_at' => null,
    ]);

    $this->actingAs($user)->getJson("/api/v1/jobs/{$job->ulid}")
        ->assertOk()
        ->assertJsonPath('data.estimated_completion_at', null);
});
