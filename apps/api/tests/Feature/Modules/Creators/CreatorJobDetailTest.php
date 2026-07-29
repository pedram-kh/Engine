<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\BrandCreatorBlacklist;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\JobsBoard\CreatorJobFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Jobs Board chunk 3 (AH-056) — the creator's JOB DETAIL endpoint.
 *
 *   GET /api/v1/creators/me/jobs/{campaign}
 *
 * Two jobs here:
 *
 * 1. The §5.34 seven-case set, RE-RUN against the detail surface. The predicate
 *    is shared with the list, but "shared" is a claim about code; only running
 *    the same seven cases against this endpoint proves the claim holds at the
 *    HTTP boundary. Every one of them must be a flat 404 — never a 403, never a
 *    partial render — so a job the creator may not see is not probeable by ULID.
 *
 * 2. The D3 EXACT-KEYSET assertions. Both job resources are pinned with exact
 *    key-list equality, not `assertJsonStructure` (which passes on a superset).
 *    This is the mechanism that stops a brand field joining a creator-facing
 *    payload by accretion — the AH-005-class boundary this chunk crosses for
 *    the first time.
 */

/** The complete, ordered key list the CARD may emit. */
const CARD_KEYS = [
    'name',
    'listing_fee',
    'listing_duration',
    'applicant_count',
    'listed_at',
    'application_status',
    // The coarse lifecycle reflection (AH-059, D5). On the CARD as well as the
    // detail — unlike `assignment_ulid`, which stays detail-only. The card
    // renders the state; the detail additionally offers the link.
    'assignment_state',
    'brand',
];

/** The complete, ordered key list the DETAIL may emit. */
const DETAIL_KEYS = [
    ...CARD_KEYS,
    'description',
    'listing_languages',
    'listing_regions',
    'listing_examples_url',
    // The D7 bridge (chunk 4, AH-058). Present on EVERY detail render, null
    // unless the caller's pair has an assignment — a conditional key would make
    // this keyset data-dependent and blunt the accretion guard.
    'assignment_ulid',
];

// ── The happy path ──────────────────────────────────────────────────────────

it('renders the detail for a visible job', function (): void {
    $f = CreatorJobFixture::make();

    $this->actingAs($f->user)->getJson($f->jobUrl())->assertOk()
        ->assertJsonPath('data.id', $f->campaign->ulid)
        ->assertJsonPath('data.type', 'creator_job')
        ->assertJsonPath('data.attributes.name', 'Autumn UGC push')
        ->assertJsonPath('data.attributes.description', 'Two Reels and three Stories per month, organic usage for 6 months.')
        ->assertJsonPath('data.attributes.listing_languages', ['en', 'fr'])
        ->assertJsonPath('data.attributes.listing_regions', ['IE', 'FR'])
        ->assertJsonPath('data.attributes.listing_examples_url', 'https://example.com/reference-work')
        ->assertJsonPath('data.attributes.brand.name', 'Northwind Coffee');
});

// ── §5.34 — the same seven cases, on the detail surface ─────────────────────

it('LEG 2 — 404s the detail for a creator who is not approved', function (ApplicationStatus $status): void {
    $f = CreatorJobFixture::make();
    $f->creator->forceFill(['application_status' => $status])->save();

    $this->actingAs($f->user)->getJson($f->jobUrl())->assertNotFound();
})->with([ApplicationStatus::Incomplete, ApplicationStatus::Pending, ApplicationStatus::Rejected]);

it('LEG 3a — 404s the detail for an agency the creator holds no relation with', function (): void {
    $f = CreatorJobFixture::make();
    $f->relation->delete();

    $this->actingAs($f->user)->getJson($f->jobUrl())->assertNotFound();
});

it('LEG 3b — 404s the detail once the relation is no longer roster', function (RelationshipStatus $status): void {
    $f = CreatorJobFixture::make();
    $f->relation->forceFill(['relationship_status' => $status])->save();

    $this->actingAs($f->user)->getJson($f->jobUrl())->assertNotFound();
})->with([
    RelationshipStatus::Ended,
    RelationshipStatus::PendingRequest,
    RelationshipStatus::Declined,
    RelationshipStatus::Prospect,
    RelationshipStatus::External,
]);

it('LEG 4 — 404s the detail for a delisted campaign', function (): void {
    $f = CreatorJobFixture::make();
    $f->campaign->forceFill(['listed_on_jobs_board' => false])->save();

    $this->actingAs($f->user)->getJson($f->jobUrl())->assertNotFound();
});

it('LEG 4b — 404s the detail for a terminal-status campaign', function (CampaignStatus $status): void {
    $f = CreatorJobFixture::make();
    $f->campaign->forceFill(['status' => $status])->save();

    $this->actingAs($f->user)->getJson($f->jobUrl())->assertNotFound();
})->with([CampaignStatus::Completed, CampaignStatus::Cancelled]);

it('LEG 5 — 404s the detail for an expired listing', function (): void {
    $f = CreatorJobFixture::make(['ends_at' => now('UTC')->subDay()]);

    $this->actingAs($f->user)->getJson($f->jobUrl())->assertNotFound();
});

it('LEG 6 — 404s the detail when the brand has hard-blacklisted the creator', function (): void {
    $f = CreatorJobFixture::make();

    BrandCreatorBlacklist::factory()->createOne(['brand_id' => $f->brand->id, 'creator_id' => $f->creator->id]);

    $this->actingAs($f->user)->getJson($f->jobUrl())->assertNotFound();
});

it('404s an unknown ULID and another agency job identically — nothing is probeable', function (): void {
    $f = CreatorJobFixture::make();

    $otherAgencyJob = Campaign::factory()->listed()->createOne([
        'agency_id' => Agency::factory()->createOne()->id,
    ]);

    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL.'/01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
    $this->actingAs($f->user)->getJson($f->jobUrl($otherAgencyJob))->assertNotFound();
});

// ── D3 — the exact keysets ──────────────────────────────────────────────────

it('emits EXACTLY the card keyset, and exactly two brand fields', function (): void {
    $f = CreatorJobFixture::make();

    $attributes = $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()->json('data.0.attributes');

    // Exact equality, not assertJsonStructure: a superset must FAIL.
    expect(array_keys($attributes))->toBe(CARD_KEYS)
        ->and(array_keys($attributes['brand']))->toBe(['name', 'logo_url']);
});

it('emits EXACTLY the detail keyset, and exactly three brand fields', function (): void {
    $f = CreatorJobFixture::make();

    $attributes = $this->actingAs($f->user)->getJson($f->jobUrl())->assertOk()->json('data.attributes');

    expect(array_keys($attributes))->toBe(DETAIL_KEYS)
        ->and(array_keys($attributes['brand']))->toBe(['name', 'logo_url', 'website_url']);
});

it('never crosses any other brand field to a creator, on either shape', function (): void {
    $f = CreatorJobFixture::make();

    // Populate every brand field a creator must NOT see with a distinctive
    // marker, so their absence is a real exclusion rather than an empty column.
    $f->brand->forceFill([
        'slug' => 'withheld-brand-slug',
        'description' => 'INTERNAL brand positioning notes',
        'industry' => 'Food and Beverage',
        'brand_safety_rules' => ['no alcohol adjacency'],
        'default_currency' => 'USD',
        'default_language' => 'de',
        'client_portal_enabled' => true,
        'website_url' => 'https://northwind.example',
    ])->save();

    $card = $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()->json('data.0');
    $detail = $this->actingAs($f->user)->getJson($f->jobUrl())->assertOk()->json('data');

    $withheld = [
        'withheld-brand-slug',
        'INTERNAL brand positioning notes',
        'Food and Beverage',
        'no alcohol adjacency',
        'client_portal_enabled',
        'logo_path',
        'default_currency',
        'default_language',
    ];

    foreach (['card' => $card, 'detail' => $detail] as $shape => $payload) {
        $flat = json_encode($payload, JSON_THROW_ON_ERROR);

        foreach ($withheld as $marker) {
            expect($flat)->not->toContain($marker, "The {$shape} shape leaked [{$marker}].");
        }
    }

    // `website_url` is the ONE field where card and detail diverge (D3: detail
    // page only), so it is asserted in both directions rather than only absent.
    expect(json_encode($card, JSON_THROW_ON_ERROR))->not->toContain('https://northwind.example');
    expect($detail['attributes']['brand']['website_url'])->toBe('https://northwind.example');
});

// ── Archived-brand posture (D3) ─────────────────────────────────────────────

it('keeps rendering a listed job whose brand has been archived', function (): void {
    $f = CreatorJobFixture::make();

    $f->brand->delete();

    // Archiving a brand is a soft delete, and LISTING STATE ALONE decides
    // visibility (D3). The campaign renders its brand as stored — the same
    // withTrashed() posture the July-Wave-4 production incident forced onto
    // Campaign::brand(). A live card must not blank out because somebody tidied
    // the brand list.
    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()
        ->assertJsonPath('data.0.attributes.brand.name', 'Northwind Coffee');

    $this->actingAs($f->user)->getJson($f->jobUrl())->assertOk()
        ->assertJsonPath('data.attributes.brand.name', 'Northwind Coffee');
});

// ── listed_at is DISPLAY ONLY (D4, review priority 5) ───────────────────────

it('emits listed_at for the recency chip and renders happily without one', function (): void {
    $f = CreatorJobFixture::make(['listed_at' => now()->subDays(3)]);

    $this->actingAs($f->user)->getJson($f->jobUrl())->assertOk()
        ->assertJsonPath('data.attributes.listed_at', $f->campaign->fresh()?->listed_at?->toIso8601String());

    // A listed campaign with a null listed_at is unreachable in this release
    // (the column ships with the board), but it is renderable — the chip just
    // does not appear. The API must not 500 on it.
    $f->campaign->forceFill(['listed_at' => null])->save();

    $this->actingAs($f->user)->getJson($f->jobUrl())->assertOk()
        ->assertJsonPath('data.attributes.listed_at', null);
});

it('NEVER lets listed_at decide visibility — the scope is the sole authority', function (): void {
    $f = CreatorJobFixture::make();

    // A null listed_at on a LISTED campaign stays visible...
    $f->campaign->forceFill(['listed_at' => null])->save();
    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($f->user)->getJson($f->jobUrl())->assertOk();

    // ...and a populated listed_at on a DELISTED campaign stays invisible.
    $f->campaign->forceFill(['listed_at' => now(), 'listed_on_jobs_board' => false])->save();
    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($f->user)->getJson($f->jobUrl())->assertNotFound();
});

// ── D7 — the assignment bridge (chunk 4, AH-058) ────────────────────────────

it('emits a null assignment_ulid until the pair actually has an assignment', function (): void {
    $f = CreatorJobFixture::make();

    // Never applied.
    $this->actingAs($f->user)->getJson($f->jobUrl())->assertOk()
        ->assertJsonPath('data.attributes.assignment_ulid', null);

    // Applied, still pending — an application is not an engagement.
    CampaignApplication::factory()->status(CampaignApplicationStatus::Pending)->createOne([
        'campaign_id' => $f->campaign->id,
        'creator_id' => $f->creator->id,
    ]);

    $this->actingAs($f->user)->getJson($f->jobUrl())->assertOk()
        ->assertJsonPath('data.attributes.application_status', 'pending')
        ->assertJsonPath('data.attributes.assignment_ulid', null);
});

it('emits the caller OWN assignment ULID once the agency has accepted them', function (): void {
    $f = CreatorJobFixture::make();

    CampaignApplication::factory()->status(CampaignApplicationStatus::Accepted)->createOne([
        'campaign_id' => $f->campaign->id,
        'creator_id' => $f->creator->id,
    ]);

    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Invited)->createOne([
        'agency_id' => $f->agency->id,
        'campaign_id' => $f->campaign->id,
        'brand_id' => $f->brand->id,
        'creator_id' => $f->creator->id,
    ]);

    $this->actingAs($f->user)->getJson($f->jobUrl())->assertOk()
        ->assertJsonPath('data.attributes.application_status', 'accepted')
        ->assertJsonPath('data.attributes.assignment_ulid', $assignment->ulid);
});

it('derives the bridge from the ASSIGNMENT, never from the accepted status', function (): void {
    $f = CreatorJobFixture::make();

    // An accepted application whose assignment is gone (the campaign's
    // engagement was unwound) still renders — with no link to offer. The page
    // degrades to the plain notice rather than sending the creator to a 404.
    CampaignApplication::factory()->status(CampaignApplicationStatus::Accepted)->createOne([
        'campaign_id' => $f->campaign->id,
        'creator_id' => $f->creator->id,
    ]);

    $this->actingAs($f->user)->getJson($f->jobUrl())->assertOk()
        ->assertJsonPath('data.attributes.application_status', 'accepted')
        ->assertJsonPath('data.attributes.assignment_ulid', null);
});

it('never bridges to ANOTHER creator assignment on the same campaign', function (): void {
    $f = CreatorJobFixture::make();

    $otherCreator = CreatorFactory::new()->approved()->createOne();

    $theirs = CampaignAssignment::factory()->status(AssignmentStatus::Invited)->createOne([
        'agency_id' => $f->agency->id,
        'campaign_id' => $f->campaign->id,
        'brand_id' => $f->brand->id,
        'creator_id' => $otherCreator->id,
    ]);

    // BREAK-REVERT: drop the subquery's `creator_id` filter and this reads
    // `$theirs->ulid` — one creator handed another's assignment identifier.
    $attributes = $this->actingAs($f->user)->getJson($f->jobUrl())->assertOk()->json('data.attributes');

    expect($attributes['assignment_ulid'])->toBeNull()
        ->and(json_encode($attributes, JSON_THROW_ON_ERROR))->not->toContain($theirs->ulid);
});

it('keeps the bridge OFF the card — the detail owns the link', function (): void {
    $f = CreatorJobFixture::make();

    CampaignAssignment::factory()->status(AssignmentStatus::Invited)->createOne([
        'agency_id' => $f->agency->id,
        'campaign_id' => $f->campaign->id,
        'brand_id' => $f->brand->id,
        'creator_id' => $f->creator->id,
    ]);

    $card = $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()->json('data.0.attributes');

    expect(array_keys($card))->toBe(CARD_KEYS);
});

// ── D5: the coarse lifecycle reflection, on BOTH surfaces (AH-059) ──────────

/**
 * Both shapes' `assignment_state`, from one request pair, so a divergence
 * between card and detail is a red rather than something a reader has to notice.
 *
 * The caller hands in its own authenticated GET: a file-level function has no
 * test case of its own to act as anybody with.
 *
 * @param  Closure(string): TestResponse<JsonResponse>  $get
 * @return array{card: mixed, detail: mixed}
 */
function assignmentStates(Closure $get, CreatorJobFixture $f): array
{
    return [
        'card' => $get(CreatorJobFixture::URL)
            ->assertOk()->json('data.0.attributes.assignment_state'),
        'detail' => $get($f->jobUrl())
            ->assertOk()->json('data.attributes.assignment_state'),
    ];
}

/** Give the caller's pair an assignment in `$status`. */
function assignPair(CreatorJobFixture $f, AssignmentStatus $status): CampaignAssignment
{
    return CampaignAssignment::factory()->status($status)->createOne([
        'agency_id' => $f->agency->id,
        'campaign_id' => $f->campaign->id,
        'brand_id' => $f->brand->id,
        'creator_id' => $f->creator->id,
    ]);
}

it('emits a null assignment_state on both surfaces until the pair has an assignment', function (): void {
    $f = CreatorJobFixture::make();

    $get = fn (string $url): TestResponse => $this->actingAs($f->user)->getJson($url);

    expect(assignmentStates($get, $f))->toBe(['card' => null, 'detail' => null]);

    // An application is not an engagement — a pending applicant has no stage to
    // reflect, and the surfaces keep rendering the application's own answer.
    CampaignApplication::factory()->status(CampaignApplicationStatus::Pending)->createOne([
        'campaign_id' => $f->campaign->id,
        'creator_id' => $f->creator->id,
    ]);

    expect(assignmentStates($get, $f))->toBe(['card' => null, 'detail' => null]);
});

it('reflects every assignment status as its coarse family, identically on card and detail', function (
    AssignmentStatus $status,
    string $expected,
): void {
    $f = CreatorJobFixture::make();

    $get = fn (string $url): TestResponse => $this->actingAs($f->user)->getJson($url);

    assignPair($f, $status);

    expect(assignmentStates($get, $f))->toBe(['card' => $expected, 'detail' => $expected]);
})->with([
    // The full 16-case catalogue over HTTP. JobLifecycleStateTest pins the
    // mapping in isolation; this pins that the mapping actually reaches both
    // payloads — the two subqueries and two resources in between are where a
    // correct mapping still fails to arrive.
    'invited → in progress' => [AssignmentStatus::Invited, 'in_progress'],
    'countered → in progress' => [AssignmentStatus::Countered, 'in_progress'],
    'accepted → in progress' => [AssignmentStatus::Accepted, 'in_progress'],
    'contracted → in progress' => [AssignmentStatus::Contracted, 'in_progress'],
    'producing → in progress' => [AssignmentStatus::Producing, 'in_progress'],
    'draft_submitted → in progress' => [AssignmentStatus::DraftSubmitted, 'in_progress'],
    'revision_requested → in progress' => [AssignmentStatus::RevisionRequested, 'in_progress'],
    // Approved but not yet posted: nothing is live, so "Completed" would
    // over-promise.
    'approved → in progress' => [AssignmentStatus::Approved, 'in_progress'],
    'posted → completed' => [AssignmentStatus::Posted, 'completed'],
    'live_verified → completed' => [AssignmentStatus::LiveVerified, 'completed'],
    'manually_verified → completed' => [AssignmentStatus::ManuallyVerified, 'completed'],
    'payment_held → completed' => [AssignmentStatus::PaymentHeld, 'completed'],
    // …and the isTerminal() trap: terminal, but a SUCCESS.
    'payment_released → completed' => [AssignmentStatus::PaymentReleased, 'completed'],
    'declined → ended' => [AssignmentStatus::Declined, 'ended'],
    'rejected → ended' => [AssignmentStatus::Rejected, 'ended'],
    'cancelled → ended' => [AssignmentStatus::Cancelled, 'ended'],
]);

it('never reflects ANOTHER creator engagement — the new subquery repeats the scoping negative', function (): void {
    $f = CreatorJobFixture::make();

    $get = fn (string $url): TestResponse => $this->actingAs($f->user)->getJson($url);

    $otherCreator = CreatorFactory::new()->approved()->createOne();

    // Their engagement is well under way on the same campaign.
    CampaignAssignment::factory()->status(AssignmentStatus::Producing)->createOne([
        'agency_id' => $f->agency->id,
        'campaign_id' => $f->campaign->id,
        'brand_id' => $f->brand->id,
        'creator_id' => $otherCreator->id,
    ]);

    // BREAK-REVERT: drop `callerAssignmentStatusSubquery()`'s `creator_id` filter
    // and both surfaces start reporting `in_progress` — one creator reading
    // another's engagement. The same negative the ULID subquery carries, repeated
    // for the new one rather than assumed to transfer.
    expect(assignmentStates($get, $f))->toBe(['card' => null, 'detail' => null]);
});

// ── D1: the rejected-chip contradiction, four cases × two surfaces ──────────

/**
 * @param  Closure(string): TestResponse<JsonResponse>  $get
 * @return array{card: array{application_status: mixed, assignment_state: mixed}, detail: array{application_status: mixed, assignment_state: mixed}}
 */
function contradictionPayloads(Closure $get, CreatorJobFixture $f): array
{
    $card = $get(CreatorJobFixture::URL)
        ->assertOk()->json('data.0.attributes');
    $detail = $get($f->jobUrl())
        ->assertOk()->json('data.attributes');

    return [
        'card' => [
            'application_status' => $card['application_status'],
            'assignment_state' => $card['assignment_state'],
        ],
        'detail' => [
            'application_status' => $detail['application_status'],
            'assignment_state' => $detail['assignment_state'],
        ],
    ];
}

/**
 * The §5.34 set for D1, at the payload layer: the four combinations of
 * (application rejected or not) × (assignment present or not), on both surfaces.
 *
 * The display rule itself is the SPA's — the branch ordering lives there, and
 * CreatorJobsPage/CreatorJobDetailPage's specs assert the rendered outcome. What
 * these cases pin is that the API hands the SPA BOTH facts in every combination,
 * because a branch cannot order over a field it was not given. Case 2 is the
 * eyes-on bug's exact shape.
 */
it('D1 case 1 — rejected application, NO assignment: the rejection is the whole story', function (): void {
    $f = CreatorJobFixture::make();

    $get = fn (string $url): TestResponse => $this->actingAs($f->user)->getJson($url);

    CampaignApplication::factory()->status(CampaignApplicationStatus::Rejected)->createOne([
        'campaign_id' => $f->campaign->id,
        'creator_id' => $f->creator->id,
    ]);

    // §5.34's retained branch: this is the case that still renders
    // "Not selected", and it must keep doing so.
    expect(contradictionPayloads($get, $f))->toBe([
        'card' => ['application_status' => 'rejected', 'assignment_state' => null],
        'detail' => ['application_status' => 'rejected', 'assignment_state' => null],
    ]);
});

it('D1 case 2 — rejected application + LIVE invitation: both facts travel (the eyes-on bug)', function (): void {
    $f = CreatorJobFixture::make();

    $get = fn (string $url): TestResponse => $this->actingAs($f->user)->getJson($url);

    // The exact shape Pedram found: the agency rejected the application, then
    // invited the creator anyway. The application row stays rejected — the
    // agency's answer to THAT application was truthful, and the later invitation
    // is a separate event.
    CampaignApplication::factory()->status(CampaignApplicationStatus::Rejected)->createOne([
        'campaign_id' => $f->campaign->id,
        'creator_id' => $f->creator->id,
    ]);

    assignPair($f, AssignmentStatus::Invited);

    expect(contradictionPayloads($get, $f))->toBe([
        'card' => ['application_status' => 'rejected', 'assignment_state' => 'in_progress'],
        'detail' => ['application_status' => 'rejected', 'assignment_state' => 'in_progress'],
    ]);
});

it('D1 case 3 — rejected application + ENDED assignment: the assignment still wins (Q2)', function (): void {
    $f = CreatorJobFixture::make();

    $get = fn (string $url): TestResponse => $this->actingAs($f->user)->getJson($url);

    CampaignApplication::factory()->status(CampaignApplicationStatus::Rejected)->createOne([
        'campaign_id' => $f->campaign->id,
        'creator_id' => $f->creator->id,
    ]);

    // The invitation was declined. Ruled at plan-pause (Q2a): the assignment
    // wins whenever one exists, including an ended one, so this reads "Ended"
    // rather than reverting to "Not selected". A pair that was ever invited never
    // reads "Not selected" again, and that is the honest story — the agency's
    // last act on the pair was an invitation, not a refusal.
    assignPair($f, AssignmentStatus::Declined);

    expect(contradictionPayloads($get, $f))->toBe([
        'card' => ['application_status' => 'rejected', 'assignment_state' => 'ended'],
        'detail' => ['application_status' => 'rejected', 'assignment_state' => 'ended'],
    ]);
});

it('D1 case 4 — accepted application + assignment: unchanged from chunk 4, now with a stage', function (): void {
    $f = CreatorJobFixture::make();

    $get = fn (string $url): TestResponse => $this->actingAs($f->user)->getJson($url);

    CampaignApplication::factory()->status(CampaignApplicationStatus::Accepted)->createOne([
        'campaign_id' => $f->campaign->id,
        'creator_id' => $f->creator->id,
    ]);

    $assignment = assignPair($f, AssignmentStatus::Contracted);

    expect(contradictionPayloads($get, $f))->toBe([
        'card' => ['application_status' => 'accepted', 'assignment_state' => 'in_progress'],
        'detail' => ['application_status' => 'accepted', 'assignment_state' => 'in_progress'],
    ]);

    // And the detail still carries the bridge to that assignment — the reflection
    // replaces the accepted NOTICE, not the link out of it.
    $this->actingAs($f->user)->getJson($f->jobUrl())->assertOk()
        ->assertJsonPath('data.attributes.assignment_ulid', $assignment->ulid);
});

it('requires authentication on the detail too', function (): void {
    $f = CreatorJobFixture::make();

    $this->getJson($f->jobUrl())->assertUnauthorized();
});

it('404s the detail for an authenticated user who is not a creator', function (): void {
    $f = CreatorJobFixture::make();
    $agencyUser = User::factory()->agencyAdmin()->createOne();

    $this->actingAs($agencyUser)->getJson($f->jobUrl())->assertNotFound();
});
