<?php

declare(strict_types=1);

use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorAvailabilityBlock;
use App\Modules\Creators\Models\CreatorKycVerification;
use App\Modules\Creators\Models\CreatorPayoutMethod;
use App\Modules\Creators\Models\CreatorPortfolioItem;
use App\Modules\Creators\Models\CreatorSocialAccount;
use App\Modules\Creators\Models\CreatorTaxProfile;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Creator tenancy contract (architecture, #34)
|--------------------------------------------------------------------------
|
| docs/03-DATA-MODEL.md §5 + docs/security/tenancy.md establish that
| Creator is a GLOBAL entity, not tenant-scoped. The agency-creator
| relationship lives on agency_creator_relations (which IS BelongsToAgency).
|
| Bug class this prevents:
|   - A future contributor adds `use BelongsToAgency` to Creator,
|     unaware of the global-entity contract. The global scope would then
|     filter Creator queries by tenant context, breaking platform-wide
|     creator search and admin SPA's creator list.
|
| Break-revert verification (per #40): temporarily add `use BelongsToAgency`
| to Creator; this test must fail. Revert and confirm pass.
|
*/

it('Creator model does NOT use BelongsToAgency trait', function (): void {
    $traits = class_uses(Creator::class);

    expect($traits)->not->toHaveKey(BelongsToAgency::class);
});

it('Creator-side satellite models do NOT use BelongsToAgency trait', function (): void {
    // The seven creator-side models all share the global entity property.
    // Tenancy comes from agency_creator_relations, not from these tables.
    $satellites = [
        CreatorSocialAccount::class,
        CreatorPortfolioItem::class,
        CreatorAvailabilityBlock::class,
        CreatorTaxProfile::class,
        CreatorPayoutMethod::class,
        CreatorKycVerification::class,
    ];

    foreach ($satellites as $class) {
        $traits = class_uses($class);
        expect($traits)->not->toHaveKey(
            BelongsToAgency::class,
            "{$class} should not use BelongsToAgency — creator-side data is global",
        );
    }
});

it('AgencyCreatorRelation DOES use BelongsToAgency trait', function (): void {
    // Counter-test: the relation table IS tenant-scoped (composite scope
    // — agency_id is the tenant column, creator_id is the satellite ref).
    $traits = class_uses(AgencyCreatorRelation::class);

    expect($traits)->toHaveKey(BelongsToAgency::class);
});
