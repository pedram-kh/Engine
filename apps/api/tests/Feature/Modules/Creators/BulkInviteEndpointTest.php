<?php

declare(strict_types=1);

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Database\Factories\AgencyMembershipFactory;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Creators\Jobs\BulkCreatorInvitationJob;
use App\Modules\Creators\Mail\ProspectCreatorInviteMail;
use App\Modules\Identity\Models\User;
use App\Modules\TrackedJobs\Models\TrackedJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function csvUpload(string $contents): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($path, $contents);

    return new UploadedFile($path, 'invitees.csv', 'text/csv', null, true);
}

function makeAgencyAdmin(): array
{
    $admin = User::factory()->create();
    $agency = AgencyFactory::new()->createOne();
    AgencyMembershipFactory::new()->createOne([
        'user_id' => $admin->id,
        'agency_id' => $agency->id,
        'role' => AgencyRole::AgencyAdmin,
        'accepted_at' => now(),
    ]);

    return ['admin' => $admin, 'agency' => $agency];
}

it('enqueues the bulk-invite job and returns 202 with TrackedJob ulid + meta', function (): void {
    Bus::fake();

    ['admin' => $admin, 'agency' => $agency] = makeAgencyAdmin();

    $csv = "email\nfirst@example.com\nsecond@example.com\nbad-email\n";

    $response = $this->actingAs($admin)
        ->postJson("/api/v1/agencies/{$agency->ulid}/creators/invitations/bulk", [
            'file' => csvUpload($csv),
        ]);

    $response->assertStatus(202)
        ->assertJsonPath('data.type', 'bulk_creator_invitation')
        ->assertJsonPath('meta.row_count', 2)
        ->assertJsonPath('meta.exceeds_soft_warning', false)
        ->assertJsonPath('meta.errors.0.code', 'invitation.email_invalid');

    Bus::assertDispatched(BulkCreatorInvitationJob::class);

    expect(TrackedJob::query()
        ->where('kind', 'bulk_creator_invitation')
        ->where('initiator_user_id', $admin->id)
        ->where('agency_id', $agency->id)
        ->count())->toBe(1);
});

it('refuses non-admin users with 403 (D-pause-9 in-controller check)', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $manager = User::factory()->create();
    AgencyMembershipFactory::new()->createOne([
        'user_id' => $manager->id,
        'agency_id' => $agency->id,
        'role' => AgencyRole::AgencyManager,
        'accepted_at' => now(),
    ]);

    $response = $this->actingAs($manager)
        ->postJson("/api/v1/agencies/{$agency->ulid}/creators/invitations/bulk", [
            'file' => csvUpload("email\nx@example.com\n"),
        ]);

    $response->assertForbidden();
});

it('processes the queued job end-to-end (creates relations + queues mail)', function (): void {
    Mail::fake();

    ['admin' => $admin, 'agency' => $agency] = makeAgencyAdmin();

    $csv = "email\nrider@example.com\npilot@example.com\n";

    $this->actingAs($admin)
        ->postJson("/api/v1/agencies/{$agency->ulid}/creators/invitations/bulk", [
            'file' => csvUpload($csv),
        ])
        ->assertStatus(202);

    expect(AgencyCreatorRelation::withoutGlobalScope(BelongsToAgencyScope::class)->where('agency_id', $agency->id)->count())->toBe(2);

    Mail::assertQueued(ProspectCreatorInviteMail::class, 2);

    $tracked = TrackedJob::query()->where('agency_id', $agency->id)->firstOrFail();
    expect($tracked->status->value)->toBe('complete')
        ->and($tracked->result)->toMatchArray(['stats' => ['invited' => 2, 'already_invited' => 0, 'failed' => 0]]);
});

it('emits BulkInviteStarted then BulkInviteCompleted audit rows', function (): void {
    Mail::fake();

    ['admin' => $admin, 'agency' => $agency] = makeAgencyAdmin();

    $this->actingAs($admin)
        ->postJson("/api/v1/agencies/{$agency->ulid}/creators/invitations/bulk", [
            'file' => csvUpload("email\nfoo@example.com\n"),
        ])
        ->assertStatus(202);

    expect(AuditLog::query()->where('action', AuditAction::BulkInviteStarted->value)->count())->toBe(1);
    expect(AuditLog::query()->where('action', AuditAction::BulkInviteCompleted->value)->count())->toBe(1);
    expect(AuditLog::query()->where('action', AuditAction::CreatorInvited->value)->count())->toBe(1);
});

it('treats re-invite as already_invited rather than duplicating the relation', function (): void {
    Mail::fake();

    ['admin' => $admin, 'agency' => $agency] = makeAgencyAdmin();

    $this->actingAs($admin)
        ->postJson("/api/v1/agencies/{$agency->ulid}/creators/invitations/bulk", [
            'file' => csvUpload("email\nrider@example.com\n"),
        ])->assertStatus(202);

    $this->actingAs($admin)
        ->postJson("/api/v1/agencies/{$agency->ulid}/creators/invitations/bulk", [
            'file' => csvUpload("email\nrider@example.com\n"),
        ])->assertStatus(202);

    expect(AgencyCreatorRelation::withoutGlobalScope(BelongsToAgencyScope::class)->where('agency_id', $agency->id)->count())->toBe(1);

    $tracked = TrackedJob::query()->where('agency_id', $agency->id)->latest('id')->firstOrFail();
    expect($tracked->result)->toMatchArray(['stats' => ['invited' => 0, 'already_invited' => 1, 'failed' => 0]]);
});
