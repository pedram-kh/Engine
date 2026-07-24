<?php

declare(strict_types=1);

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\RelationshipStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-051 (D-1) — the READ-ONLY pre-deploy contact-exposure audit command.
|--------------------------------------------------------------------------
| Pins the output shape (per-status breakdown + distinct-agencies TOTAL) and
| proves it counts exactly the relations that lose contact under the roster-only
| gate: every non-roster non-blacklisted relation. Roster (still exposes) and
| blacklisted (never exposed) are excluded.
*/

function makeExposureRelation(RelationshipStatus $status, bool $blacklisted = false, bool $withContact = false): AgencyCreatorRelation
{
    $agency = AgencyFactory::new()->createOne();
    $creator = $withContact
        ? CreatorFactory::new()->withContact()->createOne()
        : CreatorFactory::new()->createOne();

    return AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => $status,
        'is_blacklisted' => $blacklisted,
    ]);
}

it('counts every non-roster non-blacklisted relation per status with a distinct-agencies total', function (): void {
    // Affected (lose contact): 2 pending_request + 1 declined + 1 prospect + 1 ended = 5.
    makeExposureRelation(RelationshipStatus::PendingRequest, withContact: true);
    makeExposureRelation(RelationshipStatus::PendingRequest);
    makeExposureRelation(RelationshipStatus::Declined);
    makeExposureRelation(RelationshipStatus::Prospect);
    makeExposureRelation(RelationshipStatus::Ended);

    // NOT affected: roster still exposes; a blacklisted pending never exposed.
    makeExposureRelation(RelationshipStatus::Roster, withContact: true);
    makeExposureRelation(RelationshipStatus::PendingRequest, blacklisted: true, withContact: true);

    $this->artisan('relations:audit-contact-exposure')
        ->expectsOutputToContain('pending_request  2')
        ->expectsOutputToContain('declined         1')
        ->expectsOutputToContain('prospect         1')
        ->expectsOutputToContain('ended            1')
        ->expectsOutputToContain('external         0')
        ->expectsOutputToContain('of which have contact data populated: 1')
        ->expectsOutputToContain('5 relation(s) across 5 agencies currently expose contact.')
        ->assertSuccessful();
});

it('reports zero and never writes when there is nothing to report (READ-ONLY)', function (): void {
    makeExposureRelation(RelationshipStatus::Roster);

    $before = AgencyCreatorRelation::query()->withoutGlobalScopes()->get()->toArray();

    $this->artisan('relations:audit-contact-exposure')
        ->expectsOutputToContain('0 relation(s) across 0 agencies currently expose contact.')
        ->assertSuccessful();

    // Nothing mutated.
    expect(AgencyCreatorRelation::query()->withoutGlobalScopes()->get()->toArray())->toEqual($before);
});
