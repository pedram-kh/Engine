<?php

declare(strict_types=1);

use App\Modules\Agencies\Enums\BlacklistScope;
use App\Modules\Agencies\Enums\BlacklistType;
use App\Modules\Agencies\Mail\CreatorBlacklistedMail;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Agencies\Models\BrandCreatorBlacklist;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Models\Brand;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 7 — Part A: the blacklist write surface (the two write paths, D-2).
 *
 *   POST   /agencies/{agency}/creators/{creator}/blacklist
 *   DELETE /agencies/{agency}/creators/{creator}/blacklist
 *
 * Pins: the agency-wide write (relation columns), the brand-scoped write (a
 * table row, NO relation touch — D-2 no dual-write), the mandatory reason (422
 * without), the admin/manager gate (staff 403), un-blacklist (clear / soft-
 * delete), the redacted `creator.blacklisted` audit verb, and the
 * notification-policy-gated mailable.
 */
function blacklistUrl(Agency $agency, Creator $creator): string
{
    return "/api/v1/agencies/{$agency->ulid}/creators/{$creator->ulid}/blacklist";
}

function blacklistRosterCreator(Agency $agency, array $creatorAttributes = []): array
{
    $creator = Creator::factory()->approved()->createOne($creatorAttributes);
    $relation = AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
    ]);

    return [$creator, $relation];
}

function relationRow(Agency $agency, Creator $creator): ?AgencyCreatorRelation
{
    return AgencyCreatorRelation::withoutGlobalScopes()
        ->where('agency_id', $agency->id)
        ->where('creator_id', $creator->id)
        ->first();
}

function relationRowOrFail(Agency $agency, Creator $creator): AgencyCreatorRelation
{
    return AgencyCreatorRelation::withoutGlobalScopes()
        ->where('agency_id', $agency->id)
        ->where('creator_id', $creator->id)
        ->firstOrFail();
}

// ---------------------------------------------------------------------------
// Authz (D-7) — admin/manager only; staff 403
// ---------------------------------------------------------------------------

it('returns 401 when unauthenticated', function (): void {
    $agency = Agency::factory()->createOne();
    [$creator] = blacklistRosterCreator($agency);

    expect($this->postJson(blacklistUrl($agency, $creator), [
        'scope' => 'agency', 'type' => 'hard', 'reason' => 'x',
    ])->status())->toBe(401);
});

it('returns 404 for a non-member (tenancy invisibility)', function (): void {
    $agency = Agency::factory()->createOne();
    [$creator] = blacklistRosterCreator($agency);
    $outsider = User::factory()->agencyAdmin(Agency::factory()->createOne())->createOne();

    expect($this->actingAs($outsider)->postJson(blacklistUrl($agency, $creator), [
        'scope' => 'agency', 'type' => 'hard', 'reason' => 'x',
    ])->status())->toBe(404);
});

it('forbids staff from blacklisting (403 — break-revert the blacklist gate)', function (): void {
    $agency = Agency::factory()->createOne();
    $staff = User::factory()->agencyStaff($agency)->createOne();
    [$creator] = blacklistRosterCreator($agency);

    $this->actingAs($staff)->postJson(blacklistUrl($agency, $creator), [
        'scope' => 'agency', 'type' => 'hard', 'reason' => 'Spammy behaviour',
    ])->assertForbidden();

    expect(relationRowOrFail($agency, $creator)->is_blacklisted)->toBeFalse();
});

it('lets a manager blacklist', function (): void {
    $agency = Agency::factory()->createOne();
    $manager = User::factory()->agencyManager($agency)->createOne();
    [$creator] = blacklistRosterCreator($agency);

    $this->actingAs($manager)->postJson(blacklistUrl($agency, $creator), [
        'scope' => 'agency', 'type' => 'soft', 'reason' => 'Late deliveries',
    ])->assertOk();
});

// ---------------------------------------------------------------------------
// Mandatory reason (D-7) + enum validation (D-6)
// ---------------------------------------------------------------------------

it('422s without a reason (mandatory)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    [$creator] = blacklistRosterCreator($agency);

    $this->actingAs($admin)->postJson(blacklistUrl($agency, $creator), [
        'scope' => 'agency', 'type' => 'hard',
    ])->assertEnvelopeValidationErrors(['reason']);
});

it('422s on an invalid scope or type', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    [$creator] = blacklistRosterCreator($agency);

    $this->actingAs($admin)->postJson(blacklistUrl($agency, $creator), [
        'scope' => 'platform', 'type' => 'hard', 'reason' => 'x',
    ])->assertEnvelopeValidationErrors(['scope']);

    $this->actingAs($admin)->postJson(blacklistUrl($agency, $creator), [
        'scope' => 'agency', 'type' => 'nuclear', 'reason' => 'x',
    ])->assertEnvelopeValidationErrors(['type']);
});

it('422s when brand_id is sent under agency scope (a contradiction)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    [$creator] = blacklistRosterCreator($agency);
    $brand = Brand::factory()->create(['agency_id' => $agency->id]);

    $this->actingAs($admin)->postJson(blacklistUrl($agency, $creator), [
        'scope' => 'agency', 'type' => 'hard', 'reason' => 'x', 'brand_id' => $brand->ulid,
    ])->assertEnvelopeValidationErrors(['brand_id']);
});

it('422s when scope=brand but brand_id is missing', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    [$creator] = blacklistRosterCreator($agency);

    $this->actingAs($admin)->postJson(blacklistUrl($agency, $creator), [
        'scope' => 'brand', 'type' => 'hard', 'reason' => 'x',
    ])->assertEnvelopeValidationErrors(['brand_id']);
});

// ---------------------------------------------------------------------------
// A2 — agency-wide write (columns ON the relation)
// ---------------------------------------------------------------------------

it('blacklists agency-wide: sets the relation columns', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    [$creator] = blacklistRosterCreator($agency);

    $this->actingAs($admin)->postJson(blacklistUrl($agency, $creator), [
        'scope' => 'agency', 'type' => 'hard', 'reason' => 'Repeated no-shows',
    ])->assertOk()->assertJsonPath('meta.code', 'blacklist.agency.applied');

    $relation = relationRowOrFail($agency, $creator);
    expect($relation->is_blacklisted)->toBeTrue()
        ->and($relation->blacklist_scope)->toBe(BlacklistScope::Agency)
        ->and($relation->blacklist_type)->toBe(BlacklistType::Hard)
        ->and($relation->blacklist_reason)->toBe('Repeated no-shows')
        ->and($relation->blacklisted_at)->not->toBeNull()
        ->and($relation->blacklisted_by_user_id)->toBe($admin->id);
});

it('restricts an agency-wide blacklist to an existing relation (typed 422 when none)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    // Discovered-but-unconnected: approved + discoverable, NO relation.
    $creator = Creator::factory()->approved()->createOne();

    $this->actingAs($admin)->postJson(blacklistUrl($agency, $creator), [
        'scope' => 'agency', 'type' => 'hard', 'reason' => 'x',
    ])->assertStatus(422)->assertJsonPath('meta.code', 'blacklist.relation_required');

    expect(relationRow($agency, $creator))->toBeNull();
});

it('un-blacklists agency-wide: clears the relation columns', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    [$creator] = blacklistRosterCreator($agency);
    relationRowOrFail($agency, $creator)->update([
        'is_blacklisted' => true,
        'blacklist_scope' => BlacklistScope::Agency,
        'blacklist_type' => BlacklistType::Hard,
        'blacklist_reason' => 'old reason',
        'blacklisted_at' => now(),
    ]);

    $this->actingAs($admin)->deleteJson(blacklistUrl($agency, $creator), [
        'scope' => 'agency',
    ])->assertOk()->assertJsonPath('meta.code', 'blacklist.agency.lifted');

    $relation = relationRowOrFail($agency, $creator);
    expect($relation->is_blacklisted)->toBeFalse()
        ->and($relation->blacklist_scope)->toBeNull()
        ->and($relation->blacklist_type)->toBeNull()
        ->and($relation->blacklist_reason)->toBeNull()
        ->and($relation->blacklisted_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// A3 — brand-scoped write (a table row, NO relation touch — D-2)
// ---------------------------------------------------------------------------

it('blacklists brand-scoped: creates a row and does NOT touch the relation (D-2 no dual-write)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    [$creator] = blacklistRosterCreator($agency);
    $brand = Brand::factory()->create(['agency_id' => $agency->id]);

    $this->actingAs($admin)->postJson(blacklistUrl($agency, $creator), [
        'scope' => 'brand', 'type' => 'hard', 'reason' => 'Conflicts with this brand', 'brand_id' => $brand->ulid,
    ])->assertCreated()->assertJsonPath('meta.code', 'blacklist.brand.applied');

    $row = BrandCreatorBlacklist::query()
        ->where('brand_id', $brand->id)
        ->where('creator_id', $creator->id)
        ->firstOrFail();
    expect($row->blacklist_type)->toBe(BlacklistType::Hard)
        ->and($row->reason)->toBe('Conflicts with this brand')
        ->and($row->blacklisted_by_user_id)->toBe($admin->id);

    // D-2: the relation is UNTOUCHED — no dual-write.
    $relation = relationRowOrFail($agency, $creator);
    expect($relation->is_blacklisted)->toBeFalse()
        ->and($relation->blacklist_scope)->toBeNull();
});

it('brand-scoped blacklist needs no relation (the table FKs brand+creator directly)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->approved()->createOne(); // no relation
    $brand = Brand::factory()->create(['agency_id' => $agency->id]);

    $this->actingAs($admin)->postJson(blacklistUrl($agency, $creator), [
        'scope' => 'brand', 'type' => 'soft', 'reason' => 'x', 'brand_id' => $brand->ulid,
    ])->assertCreated();

    expect(BrandCreatorBlacklist::query()->where('brand_id', $brand->id)->count())->toBe(1);
});

it('un-blacklists brand-scoped: soft-deletes the row (preserves history, D-3)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    [$creator] = blacklistRosterCreator($agency);
    $brand = Brand::factory()->create(['agency_id' => $agency->id]);
    $row = BrandCreatorBlacklist::factory()->create([
        'brand_id' => $brand->id,
        'creator_id' => $creator->id,
    ]);

    $this->actingAs($admin)->deleteJson(blacklistUrl($agency, $creator), [
        'scope' => 'brand', 'brand_id' => $brand->ulid,
    ])->assertOk()->assertJsonPath('meta.code', 'blacklist.brand.lifted');

    expect(BrandCreatorBlacklist::query()->find($row->id))->toBeNull()
        ->and(BrandCreatorBlacklist::withTrashed()->findOrFail($row->id)->deleted_at)->not->toBeNull();
});

it('isolates the brand-scoped write to the calling agency (a foreign brand 422s)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    [$creator] = blacklistRosterCreator($agency);
    $foreignBrand = Brand::factory()->create(['agency_id' => Agency::factory()->createOne()->id]);

    $this->actingAs($admin)->postJson(blacklistUrl($agency, $creator), [
        'scope' => 'brand', 'type' => 'hard', 'reason' => 'x', 'brand_id' => $foreignBrand->ulid,
    ])->assertStatus(422)->assertJsonPath('meta.code', 'blacklist.brand_not_found');

    expect(BrandCreatorBlacklist::query()->where('brand_id', $foreignBrand->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// A5 — the redacted `creator.blacklisted` audit verb (D-5)
// ---------------------------------------------------------------------------

it('logs creator.blacklisted with scope/type metadata and NO reason (B4 redaction)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    [$creator] = blacklistRosterCreator($agency);
    $secret = 'SECRET-REASON-must-not-leak';

    $this->actingAs($admin)->postJson(blacklistUrl($agency, $creator), [
        'scope' => 'agency', 'type' => 'hard', 'reason' => $secret,
    ])->assertOk();

    $row = AuditLog::query()
        ->where('action', AuditAction::CreatorBlacklisted->value)
        ->latest('id')
        ->firstOrFail();
    expect($row->reason)->toBeNull();

    $metadata = (string) json_encode($row->metadata);
    expect($metadata)->toContain('"scope":"agency"')
        ->and($metadata)->toContain('"type":"hard"');

    // The reason text must appear NOWHERE in ANY audit row (the privacy pin).
    foreach (DB::table('audit_logs')->get() as $auditRow) {
        foreach (['before', 'after', 'metadata', 'reason'] as $column) {
            expect((string) $auditRow->{$column})->not->toContain($secret);
        }
    }
});

// ---------------------------------------------------------------------------
// A6 — notification policy (D-4)
// ---------------------------------------------------------------------------

it('does NOT email the creator when the notification policy is off (default)', function (): void {
    Mail::fake();
    $agency = Agency::factory()->createOne(); // settings default — policy off
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    [$creator] = blacklistRosterCreator($agency);

    $this->actingAs($admin)->postJson(blacklistUrl($agency, $creator), [
        'scope' => 'agency', 'type' => 'hard', 'reason' => 'x',
    ])->assertOk();

    Mail::assertNothingQueued();
    expect(relationRowOrFail($agency, $creator)->notification_sent_at)->toBeNull();
});

it('emails the creator in their locale and stamps notification_sent_at when policy is on', function (): void {
    Mail::fake();
    $agency = Agency::factory()->createOne([
        'name' => 'Acme Talent',
        'settings' => ['blacklist_notification_policy' => true],
    ]);
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creatorUser = User::factory()->create(['preferred_language' => 'pt']);
    [$creator] = blacklistRosterCreator($agency, ['user_id' => $creatorUser->id]);

    $this->actingAs($admin)->postJson(blacklistUrl($agency, $creator), [
        'scope' => 'agency', 'type' => 'hard', 'reason' => 'x',
    ])->assertOk();

    Mail::assertQueued(CreatorBlacklistedMail::class, function (CreatorBlacklistedMail $mail) use ($creatorUser): bool {
        return $mail->hasTo($creatorUser->email)
            && $mail->locale === 'pt'
            && $mail->agencyName === 'Acme Talent';
    });
    expect(relationRowOrFail($agency, $creator)->notification_sent_at)->not->toBeNull();
});
