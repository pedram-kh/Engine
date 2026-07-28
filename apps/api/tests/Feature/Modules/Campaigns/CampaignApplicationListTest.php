<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-058 (chunk 4, D1) — the campaign-detail Applications TAB's list endpoint.
 *
 *   GET /api/v1/agencies/{agency}/campaigns/{campaign}/applications
 *
 * Four things are pinned, in rising order of how badly they would bite:
 *   - the exact keyset (what an agency reads about an applicant, key for key);
 *   - pending-first ordering (the tab's information design);
 *   - the pending-ONLY badge count, distinguished from the interest-semantics
 *     `applicant_count` chunk 3 ships to creators;
 *   - the negative set: another agency's campaign, a member-less caller, and an
 *     application belonging to a different campaign of the SAME agency.
 */
function applicationsUrl(Agency $agency, Campaign $campaign): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/applications";
}

/**
 * @return array{agency: Agency, campaign: Campaign, admin: User}
 */
function applicationListSetup(): array
{
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->listed()->createOne([
        'agency_id' => $agency->id,
        'brand_id' => $brand->id,
    ]);

    return [
        'agency' => $agency,
        'campaign' => $campaign,
        'admin' => User::factory()->agencyAdmin($agency)->createOne(),
    ];
}

function makeApplication(Campaign $campaign, CampaignApplicationStatus $status, ?string $note = null, ?string $displayName = null): CampaignApplication
{
    $creator = Creator::factory()->approved()->createOne(
        $displayName === null ? [] : ['display_name' => $displayName],
    );

    $factory = CampaignApplication::factory()->status($status);

    if ($note !== null) {
        $factory = $factory->withNote($note);
    }

    return $factory->createOne([
        'campaign_id' => $campaign->id,
        'agency_id' => $campaign->agency_id,
        'creator_id' => $creator->id,
    ]);
}

// ── Shape ───────────────────────────────────────────────────────────────────

it('emits an EXACT keyset per row — roster-level identity, the note, the two timestamps', function (): void {
    $s = applicationListSetup();
    makeApplication($s['campaign'], CampaignApplicationStatus::Pending, 'I shot a similar campaign last spring.', 'Maria Lopez');

    $response = $this->actingAs($s['admin'])
        ->getJson(applicationsUrl($s['agency'], $s['campaign']))
        ->assertOk();

    $row = $response->json('data.0');
    $attributes = $row['attributes'];

    expect($row['type'])->toBe('campaign_application_list_item')
        // The accretion guard (chunk 3's DETAIL_KEYS discipline, reused): a field
        // added here without a decision reddens this test rather than quietly
        // widening what an agency reads about an applicant.
        ->and(array_keys($attributes))->toBe([
            'status', 'note', 'applied_at', 'responded_at', 'creator',
        ])
        ->and(array_keys($attributes['creator']))->toBe(['id', 'display_name', 'avatar_url'])
        ->and($attributes['status'])->toBe('pending')
        ->and($attributes['note'])->toBe('I shot a similar campaign last spring.')
        ->and($attributes['applied_at'])->not->toBeNull()
        ->and($attributes['responded_at'])->toBeNull()
        ->and($attributes['creator']['display_name'])->toBe('Maria Lopez');
});

it('emits NO contact details — the tab creates no new exposure beyond the roster', function (): void {
    $s = applicationListSetup();
    $application = makeApplication($s['campaign'], CampaignApplicationStatus::Pending);
    $user = User::factory()->creator()->createOne(['email' => 'maria@example.test']);
    $application->creator->forceFill(['user_id' => $user->id])->save();

    $body = $this->actingAs($s['admin'])
        ->getJson(applicationsUrl($s['agency'], $s['campaign']))
        ->assertOk()
        ->getContent();

    // Applicants are rostered by definition, so the agency CAN already read this
    // creator's email on the roster surface (where CreatorPolicy applies it). The
    // list row simply does not carry it — one exposure surface, not two.
    expect($body)->not->toContain('maria@example.test');
});

// ── Ordering + the badge count ──────────────────────────────────────────────

it('orders PENDING first, then newest', function (): void {
    $s = applicationListSetup();
    $oldPending = makeApplication($s['campaign'], CampaignApplicationStatus::Pending);
    $accepted = makeApplication($s['campaign'], CampaignApplicationStatus::Accepted);
    $rejected = makeApplication($s['campaign'], CampaignApplicationStatus::Rejected);
    $newPending = makeApplication($s['campaign'], CampaignApplicationStatus::Pending);

    $ids = $this->actingAs($s['admin'])
        ->getJson(applicationsUrl($s['agency'], $s['campaign']))
        ->assertOk()
        ->json('data.*.id');

    expect($ids)->toBe([
        // Both pending rows first, newest of them at the top…
        $newPending->ulid,
        $oldPending->ulid,
        // …then the answered rows, newest first. An answered application is
        // history; the rows needing a decision are why the tab was opened.
        $rejected->ulid,
        $accepted->ulid,
    ]);
});

it('meta.pending_total counts PENDING ONLY — never the interest-semantics applicant count', function (): void {
    $s = applicationListSetup();
    makeApplication($s['campaign'], CampaignApplicationStatus::Pending);
    makeApplication($s['campaign'], CampaignApplicationStatus::Pending);
    makeApplication($s['campaign'], CampaignApplicationStatus::Accepted);
    makeApplication($s['campaign'], CampaignApplicationStatus::Rejected);

    $this->actingAs($s['admin'])
        ->getJson(applicationsUrl($s['agency'], $s['campaign']))
        ->assertOk()
        // total = every application (the page's denominator)…
        ->assertJsonPath('meta.total', 4)
        // …pending_total = the badge. Chunk 3's `applicant_count` is unfiltered
        // by design (interest semantics, for the creator weighing their odds);
        // using it here would give every campaign that ever had an application a
        // permanent, unclearable badge.
        ->assertJsonPath('meta.pending_total', 2);
});

it('the status filter narrows the page but NOT the badge, and an unknown value returns nothing', function (): void {
    $s = applicationListSetup();
    makeApplication($s['campaign'], CampaignApplicationStatus::Pending);
    makeApplication($s['campaign'], CampaignApplicationStatus::Rejected);

    $this->actingAs($s['admin'])
        ->getJson(applicationsUrl($s['agency'], $s['campaign']).'?status=rejected')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.status', 'rejected')
        // "This many decisions are waiting" does not change because the operator
        // is currently looking at the rejected ones.
        ->assertJsonPath('meta.pending_total', 1);

    // The unknown-value convention: an empty page, never a silently widened one.
    $this->actingAs($s['admin'])
        ->getJson(applicationsUrl($s['agency'], $s['campaign']).'?status=not_a_status')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0);
});

it('paginates with a clamped per_page', function (): void {
    $s = applicationListSetup();
    makeApplication($s['campaign'], CampaignApplicationStatus::Pending);
    makeApplication($s['campaign'], CampaignApplicationStatus::Pending);

    $this->actingAs($s['admin'])
        ->getJson(applicationsUrl($s['agency'], $s['campaign']).'?per_page=1&page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.page', 2)
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.last_page', 2);

    $this->actingAs($s['admin'])
        ->getJson(applicationsUrl($s['agency'], $s['campaign']).'?per_page=9999')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

// ── §5.34 — the negative set ────────────────────────────────────────────────

it('404s another agency\'s campaign — never a 403 (§5.4 non-fingerprinting)', function (): void {
    $s = applicationListSetup();
    $other = applicationListSetup();
    makeApplication($other['campaign'], CampaignApplicationStatus::Pending);

    // The caller is a real admin of their OWN agency, and the campaign really
    // exists. The only thing wrong is the pairing.
    $this->actingAs($s['admin'])
        ->getJson(applicationsUrl($s['agency'], $other['campaign']))
        ->assertNotFound();

    // And the same call against the OTHER agency's own URL is refused too — 404
    // again, because the tenancy layer makes an agency the caller is not a member
    // of INVISIBLE rather than forbidden. Two different reasons, one
    // indistinguishable code, which is the point.
    $this->actingAs($s['admin'])
        ->getJson(applicationsUrl($other['agency'], $other['campaign']))
        ->assertNotFound();
});

it('404s a caller with no membership of the agency (tenancy invisibility)', function (): void {
    $s = applicationListSetup();
    makeApplication($s['campaign'], CampaignApplicationStatus::Pending);
    $stranger = User::factory()->agencyAdmin(Agency::factory()->createOne())->createOne();

    // Not 403: the tenancy layer resolves the agency binding against the
    // caller's memberships, so an agency they do not belong to does not exist as
    // far as they are concerned (the drafts-endpoint precedent).
    $this->actingAs($stranger)
        ->getJson(applicationsUrl($s['agency'], $s['campaign']))
        ->assertNotFound();
});

it('lets STAFF read the list — the list is view-gated, the actions are not (Q4)', function (): void {
    $s = applicationListSetup();
    makeApplication($s['campaign'], CampaignApplicationStatus::Pending);
    $staff = User::factory()->agencyStaff($s['agency'])->createOne();

    $this->actingAs($staff)
        ->getJson(applicationsUrl($s['agency'], $s['campaign']))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('never leaks an application from another campaign of the SAME agency', function (): void {
    $s = applicationListSetup();
    $mine = makeApplication($s['campaign'], CampaignApplicationStatus::Pending);

    $sibling = Campaign::factory()->listed()->createOne([
        'agency_id' => $s['agency']->id,
        'brand_id' => $s['campaign']->brand_id,
    ]);
    makeApplication($sibling, CampaignApplicationStatus::Pending);

    // The tenancy scope would let both through — the campaign filter is what
    // separates them, so it gets its own case.
    $this->actingAs($s['admin'])
        ->getJson(applicationsUrl($s['agency'], $s['campaign']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->ulid)
        ->assertJsonPath('meta.pending_total', 1);
});
