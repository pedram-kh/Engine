<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/MessageInboxFixtures.php';

/** AH-119 A1 — the two additive columns, pinned before anything reads them. */
it('adds agency_creator_relations.owner_user_id as a nullable column that existing rows leave NULL', function (): void {
    expect(Schema::hasColumn('agency_creator_relations', 'owner_user_id'))->toBeTrue();

    ['agency' => $agency] = inboxWorld();
    expect(inboxRelation($agency, inboxCreator())->fresh()?->owner_user_id)->toBeNull();

    $index = collect(Schema::getIndexes('agency_creator_relations'))->firstWhere('name', 'idx_acr_agency_owner');
    expect($index['columns'] ?? null)->toBe(['agency_id', 'owner_user_id']);
});

it('nulls the owner (and keeps the relationship) when the owner user is deleted', function (): void {
    ['agency' => $agency, 'staff' => $staff] = inboxWorld();
    $relation = inboxRelation($agency, inboxCreator(), owner: $staff);
    expect($relation->owner_user_id)->toBe($staff->id);

    DB::table('agency_users')->where('user_id', $staff->id)->delete();
    User::query()->whereKey($staff->id)->forceDelete();

    expect(AgencyCreatorRelation::query()->find($relation->id)?->owner_user_id)->toBeNull();
});

it('adds brands.color as a nullable string that existing brands leave NULL', function (): void {
    expect(Schema::hasColumn('brands', 'color'))->toBeTrue();

    ['agency' => $agency] = inboxWorld();
    expect(Brand::factory()->forAgency($agency->id)->createOne()->fresh()?->color)->toBeNull();
});
