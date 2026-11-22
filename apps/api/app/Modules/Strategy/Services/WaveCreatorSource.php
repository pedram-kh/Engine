<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Enums\BlacklistType;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Waves\Models\Wave;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who may be put in a wave (PL-3.1 plan 1.5, P2, Q4, G2): the creators who applied to the wave's linked campaign or have a live
 * assignment on it, built on the SERVER and re-checked inside the wave lock by every writer, never trusted from the client.
 *
 * Excluded: a creator who is not approved, who is erased or scheduled for deletion (`visibleToAgencies`), or who is
 * hard-blacklisted by EITHER predicate of `AssignmentInviteGate::isHardBlacklisted` (agency-wide hard, or brand-scoped hard
 * for the campaign's brand), written here as set predicates. NOT excluded: a creator who is not discoverable (Q4: they applied;
 * this picker invites nobody) and a creator who is exclusive to another brand (the invite gate does not check exclusivity;
 * the list only carries a read-only hint).
 *
 * Tenancy: the creators table is global, so tenant safety comes from the campaign; every campaign-side lookup names the agency
 * explicitly and drops the global scope, so the same code runs in a creator's request (the accept listener) with no agency
 * context.
 */
final class WaveCreatorSource
{
    public static function campaign(Wave $wave): ?Campaign
    {
        if ($wave->campaign_id === null) {
            return null;
        }

        return Campaign::query()->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('agency_id', $wave->agency_id)->whereKey($wave->campaign_id)->first();
    }

    /**
     * @return Builder<Creator>
     */
    public static function eligible(Wave $wave, Campaign $campaign): Builder
    {
        $applied = DB::table('campaign_applications')->select('creator_id')
            ->where('agency_id', $wave->agency_id)->where('campaign_id', $campaign->id);
        $assigned = DB::table('campaign_assignments')->select('creator_id')
            ->where('agency_id', $wave->agency_id)->where('campaign_id', $campaign->id)->whereNull('deleted_at');

        return Creator::query()
            ->where('creators.application_status', ApplicationStatus::Approved->value)
            ->visibleToAgencies()
            ->where(function (Builder $q) use ($applied, $assigned): void {
                $q->whereIn('creators.id', $applied)->orWhereIn('creators.id', $assigned);
            })
            // Agency-wide hard blacklist.
            ->whereNotExists(function ($sub) use ($wave): void {
                $sub->from('agency_creator_relations')->selectRaw('1')
                    ->whereColumn('agency_creator_relations.creator_id', 'creators.id')
                    ->where('agency_creator_relations.agency_id', $wave->agency_id)
                    ->where('agency_creator_relations.is_blacklisted', true)
                    ->where('agency_creator_relations.blacklist_type', BlacklistType::Hard->value);
            })
            // Brand-scoped hard blacklist, for THIS campaign's brand only.
            ->whereNotExists(function ($sub) use ($campaign): void {
                $sub->from('brand_creator_blacklists')->selectRaw('1')
                    ->whereColumn('brand_creator_blacklists.creator_id', 'creators.id')
                    ->where('brand_creator_blacklists.brand_id', $campaign->brand_id)
                    ->where('brand_creator_blacklists.blacklist_type', BlacklistType::Hard->value)
                    ->whereNull('brand_creator_blacklists.deleted_at');
            });
    }

    /**
     * The creators of these ulids, all of which must be eligible. ONE refusal for "unknown", "not eligible" and "erased".
     *
     * @param  list<string>  $ulids
     * @return Collection<string, Creator> keyed by ulid
     */
    public static function require(Wave $wave, Campaign $campaign, array $ulids): Collection
    {
        $found = self::eligible($wave, $campaign)->whereIn('creators.ulid', $ulids)->get()->keyBy('ulid');
        foreach ($ulids as $ulid) {
            if (! $found->has($ulid)) {
                throw StrategyRuleViolation::creatorNotEligible();
            }
        }

        return $found;
    }

    /**
     * The wave's applications and live assignments by creator: TWO queries however many slots there are.
     *
     * @return array{applications: Collection<int, CampaignApplication>, assignments: Collection<int, CampaignAssignment>}
     */
    public static function maps(Wave $wave, ?Campaign $campaign): array
    {
        if ($campaign === null) {
            return ['applications' => new Collection, 'assignments' => new Collection];
        }

        return [
            'applications' => CampaignApplication::query()->withoutGlobalScope(BelongsToAgencyScope::class)
                ->where('agency_id', $wave->agency_id)->where('campaign_id', $campaign->id)
                ->orderBy('id')->get()->keyBy('creator_id'),
            'assignments' => CampaignAssignment::query()->withoutGlobalScope(BelongsToAgencyScope::class)
                ->where('agency_id', $wave->agency_id)->where('campaign_id', $campaign->id)
                ->orderBy('id')->get()->keyBy('creator_id'),
        ];
    }
}
