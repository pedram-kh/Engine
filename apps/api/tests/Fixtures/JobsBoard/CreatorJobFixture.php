<?php

declare(strict_types=1);

namespace Tests\Fixtures\JobsBoard;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;

/**
 * The jobs-board happy path (Jobs Board chunk 3, AH-056): an APPROVED creator,
 * ROSTERED with an agency, which has a LISTED, non-terminal, unexpired campaign
 * whose brand has not blacklisted them.
 *
 * It is a shared fixture rather than a per-file helper for one reason: the
 * §5.34 seven-case negative set is run against FOUR surfaces (list, detail,
 * apply, fan-out recipients), and the cases are only disjoint if all four start
 * from a byte-identical happy path. A copy-pasted fixture that drifts in one
 * file turns "this leg is what excluded the creator" into a guess.
 *
 * Named `CreatorJobFixture`, deliberately not `jobsBoardFixture` — that global
 * helper already exists in `CampaignJobsBoardListingTest` for the AGENCY side,
 * and two near-identically named fixtures on opposite sides of the same feature
 * is a trap.
 */
final class CreatorJobFixture
{
    public const string URL = '/api/v1/creators/me/jobs';

    public const string BRAND_NAME = 'Northwind Coffee';

    public const string CAMPAIGN_NAME = 'Autumn UGC push';

    public function __construct(
        public readonly User $user,
        public readonly Creator $creator,
        public readonly Agency $agency,
        public readonly Brand $brand,
        public readonly Campaign $campaign,
        public readonly AgencyCreatorRelation $relation,
    ) {}

    /**
     * @param  array<string, mixed>  $campaignOverrides
     */
    public static function make(array $campaignOverrides = []): self
    {
        $user = User::factory()->createOne();
        $creator = CreatorFactory::new()->approved()->createOne(['user_id' => $user->id]);

        $agency = Agency::factory()->createOne();
        $brand = Brand::factory()->forAgency($agency->id)->createOne(['name' => self::BRAND_NAME]);

        $relation = AgencyCreatorRelation::factory()->createOne([
            'agency_id' => $agency->id,
            'creator_id' => $creator->id,
            'relationship_status' => RelationshipStatus::Roster,
            'is_blacklisted' => false,
        ]);

        $campaign = Campaign::factory()
            ->listed()
            ->createOne(array_merge([
                'agency_id' => $agency->id,
                'brand_id' => $brand->id,
                'name' => self::CAMPAIGN_NAME,
                'ends_at' => null,
            ], $campaignOverrides));

        return new self($user, $creator, $agency, $brand, $campaign, $relation);
    }

    public function jobUrl(?Campaign $campaign = null): string
    {
        return self::URL.'/'.($campaign ?? $this->campaign)->ulid;
    }

    public function applyUrl(?Campaign $campaign = null): string
    {
        return $this->jobUrl($campaign).'/apply';
    }

    /**
     * The campaign ULIDs a board response returned, in order.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public static function ulids(array $payload): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $payload['data'];

        return array_values(array_map(
            static fn (array $row): string => (string) $row['id'],
            $rows,
        ));
    }
}
