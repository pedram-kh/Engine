<?php

declare(strict_types=1);

use App\Modules\Audit\Database\Factories\AuditLogFactory;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Campaigns\Database\Factories\CampaignAssignmentFactory;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 13 (D-4) — the KYC review queue + the read-only creator-detail
 * history surfaces (assignments + per-creator audit trail).
 *
 *   GET /api/v1/admin/creators?kyc_status=pending
 *   GET /api/v1/admin/creators/{creator}/assignments
 *   GET /api/v1/admin/creators/{creator}/audit-logs
 *
 * platform_admin-gated (CreatorPolicy); cross-agency by design (the
 * assignment history strips the tenant scope). Both history reads are
 * payment-free — the creator-detail payment section is a coming-soon
 * block (D-13) lit up in S10.
 */
function makeHistoryAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

// ─── KYC queue (the orthogonal ?kyc_status= filter) ─────────────────────

it('filters the creator list by kyc_status, distinct from application status', function (): void {
    $admin = makeHistoryAdmin();
    $pendingKyc = CreatorFactory::new()->submitted()->createOne(['kyc_status' => KycStatus::Pending->value]);
    CreatorFactory::new()->submitted()->createOne(['kyc_status' => KycStatus::Verified->value]);
    CreatorFactory::new()->submitted()->createOne(['kyc_status' => KycStatus::None->value]);

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson('/api/v1/admin/creators?kyc_status=pending');

    expect($response->status())->toBe(200);
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($pendingKyc->ulid);
    expect($response->json('data.0.attributes.kyc_status'))->toBe('pending');
});

it('returns an empty page for an unknown kyc_status value', function (): void {
    $admin = makeHistoryAdmin();
    CreatorFactory::new()->submitted()->createOne(['kyc_status' => KycStatus::Pending->value]);

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson('/api/v1/admin/creators?kyc_status=not_a_status');

    expect($response->status())->toBe(200);
    expect($response->json('meta.total'))->toBe(0);
});

// ─── Assignment history (cross-agency, payment-free) ────────────────────

it('lists a creator assignment history across agencies (tenant scope stripped)', function (): void {
    $admin = makeHistoryAdmin();
    $creator = CreatorFactory::new()->approved()->createOne();

    CampaignAssignmentFactory::new()
        ->status(AssignmentStatus::Accepted)
        ->createOne(['creator_id' => $creator->id]);
    CampaignAssignmentFactory::new()
        ->status(AssignmentStatus::Invited)
        ->createOne(['creator_id' => $creator->id]);
    // An unrelated creator's assignment must NOT leak in.
    CampaignAssignmentFactory::new()->createOne();

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson("/api/v1/admin/creators/{$creator->ulid}/assignments");

    expect($response->status())->toBe(200);
    expect($response->json('meta.total'))->toBe(2);
    expect($response->json('data.0.attributes'))->toHaveKeys([
        'status', 'campaign_name', 'brand_name', 'agency_name', 'created_at',
    ]);
    // Payment columns are deliberately absent (the coming-soon block).
    expect($response->json('data.0.attributes'))->not->toHaveKey('agreed_fee_minor_units');
});

it('403s a non-admin hitting the assignment history', function (): void {
    $creator = CreatorFactory::new()->approved()->createOne();
    $nonAdmin = User::factory()->create([
        'type' => UserType::Creator,
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->actingAs($nonAdmin, 'web_admin')
        ->getJson("/api/v1/admin/creators/{$creator->ulid}/assignments");

    expect($response->status())->toBe(403);
});

it('401s an unauthenticated assignment-history request', function (): void {
    $creator = CreatorFactory::new()->approved()->createOne();

    expect($this->getJson("/api/v1/admin/creators/{$creator->ulid}/assignments")->status())->toBe(401);
});

// ─── Per-creator audit trail ────────────────────────────────────────────

it('returns the audit rows whose subject is this creator', function (): void {
    $admin = makeHistoryAdmin();
    $creator = CreatorFactory::new()->approved()->createOne();
    $other = CreatorFactory::new()->approved()->createOne();

    auditRowFor($creator, AuditAction::CreatorApproved);
    auditRowFor($creator, AuditAction::CreatorKycManuallyVerified);
    auditRowFor($other, AuditAction::CreatorApproved);

    // The Audited trait may also emit rows on creator creation, so the
    // exact total is whatever is subject-scoped to THIS creator — the
    // assertion that matters is the scope (no $other rows leak in).
    $expected = AuditLog::query()
        ->where('subject_type', $creator->getMorphClass())
        ->where('subject_id', $creator->id)
        ->count();

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson("/api/v1/admin/creators/{$creator->ulid}/audit-logs");

    expect($response->status())->toBe(200);
    expect($response->json('meta.total'))->toBe($expected);
    // The two explicit verbs are present; $other's row is not.
    /** @var list<array{attributes: array{action: string}}> $rows */
    $rows = $response->json('data');
    $actions = array_map(static fn (array $row): string => $row['attributes']['action'], $rows);
    expect($actions)->toContain('creator.approved')
        ->and($actions)->toContain('creator.kyc.manually_verified');
    expect($response->json('data.0.attributes'))->toHaveKeys([
        'action', 'actor_name', 'actor_email', 'reason', 'created_at',
    ]);
});

it('403s a non-admin hitting the creator audit trail', function (): void {
    $creator = CreatorFactory::new()->approved()->createOne();
    $nonAdmin = User::factory()->create([
        'type' => UserType::Creator,
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->actingAs($nonAdmin, 'web_admin')
        ->getJson("/api/v1/admin/creators/{$creator->ulid}/audit-logs");

    expect($response->status())->toBe(403);
});

function auditRowFor(Creator $creator, AuditAction $action): void
{
    AuditLogFactory::new()->create([
        'action' => $action,
        'subject_type' => $creator->getMorphClass(),
        'subject_id' => $creator->id,
        'subject_ulid' => $creator->ulid,
        'created_at' => now(),
    ]);
}
