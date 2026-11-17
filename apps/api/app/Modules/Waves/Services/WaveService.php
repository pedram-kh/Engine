<?php

declare(strict_types=1);

namespace App\Modules\Waves\Services;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Enums\BrandStatus;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Enums\WaveStatus;
use App\Modules\Waves\Models\Wave;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Wave writes (PL-1.1). The rules live here, not in the controller:
 *
 *   - an archived brand is read-only (create AND edit);
 *   - a linked campaign must belong to this agency AND this brand, must not be soft-deleted and must not be
 *     Cancelled; every "no" is the SAME `wave.campaign_not_available`, so a response never reveals whether a
 *     foreign, deleted or cancelled campaign exists;
 *   - one wave per campaign: the partial unique index is the guard, the pre-check a courtesy;
 *   - an existing link can be neither changed nor removed (PL-1.2); a link can be SET while the wave has none.
 */
final class WaveService
{
    /**
     * @param  array{name: string, go_live_on: string, markets: list<string>, campaign_id?: string|null}  $data
     */
    public function create(Agency $agency, Brand $brand, User $actor, array $data): Wave
    {
        $this->assertBrandWritable($brand);

        $campaign = $this->linkableCampaign($agency, $brand, $data['campaign_id'] ?? null);

        try {
            $wave = DB::transaction(function () use ($agency, $brand, $actor, $data, $campaign): Wave {
                return Wave::query()->create([
                    'agency_id' => $agency->id,
                    'brand_id' => $brand->id,
                    'campaign_id' => $campaign?->id,
                    'name' => trim($data['name']),
                    'go_live_on' => $data['go_live_on'],
                    'markets' => array_values($data['markets']),
                    'status' => WaveStatus::Draft,
                    'created_by_user_id' => $actor->id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw WaveRuleViolation::campaignAlreadyLinked();
        }

        Audit::log(
            action: AuditAction::WaveCreated,
            subject: $wave,
            after: $wave->toArray(),
        );

        return $wave;
    }

    /**
     * @param  array<string, mixed>  $data  A partial body: name, go_live_on, markets, campaign_id.
     */
    public function update(Agency $agency, Brand $brand, Wave $wave, array $data): Wave
    {
        $this->assertBrandWritable($brand);

        $before = $wave->toArray();
        $newCampaign = null;

        if (array_key_exists('campaign_id', $data)) {
            $requested = $data['campaign_id'];
            if ($wave->campaign_id !== null) {
                $currentUlid = Campaign::query()->withTrashed()->whereKey($wave->campaign_id)->value('ulid');
                if ($requested !== $currentUlid) {
                    throw WaveRuleViolation::campaignLinkLocked();
                }
            } elseif ($requested !== null) {
                $newCampaign = $this->linkableCampaign($agency, $brand, $requested);
            }
        }

        if (array_key_exists('name', $data)) {
            $wave->name = trim((string) $data['name']);
        }
        if (array_key_exists('go_live_on', $data)) {
            $wave->go_live_on = $data['go_live_on'];
        }
        if (array_key_exists('markets', $data)) {
            $wave->markets = array_values((array) $data['markets']);
        }
        if ($newCampaign !== null) {
            $wave->campaign_id = $newCampaign->id;
        }

        if (! $wave->isDirty()) {
            return $wave;
        }

        try {
            DB::transaction(function () use ($wave): void {
                $wave->save();
            });
        } catch (UniqueConstraintViolationException) {
            throw WaveRuleViolation::campaignAlreadyLinked();
        }

        Audit::log(
            action: AuditAction::WaveUpdated,
            subject: $wave,
            before: $before,
            after: $wave->fresh()?->toArray() ?? [],
        );

        return $wave;
    }

    private function assertBrandWritable(Brand $brand): void
    {
        if ($brand->trashed() || $brand->status === BrandStatus::Archived) {
            throw WaveRuleViolation::brandArchived();
        }
    }

    /**
     * Resolve the campaign a wave may link to, or null for "no link". Throws `campaign_not_available` for anything
     * that is not this agency's, this brand's, live and not cancelled; `campaign_already_linked` when a live wave
     * already holds it (the unique index remains the real guard against a race).
     */
    private function linkableCampaign(Agency $agency, Brand $brand, ?string $campaignUlid): ?Campaign
    {
        if ($campaignUlid === null) {
            return null;
        }

        $campaign = Campaign::query()
            ->where('ulid', $campaignUlid)
            ->where('agency_id', $agency->id)
            ->where('brand_id', $brand->id)
            ->where('status', '!=', CampaignStatus::Cancelled->value)
            ->first();

        if ($campaign === null) {
            throw WaveRuleViolation::campaignNotAvailable();
        }

        if (Wave::query()->where('campaign_id', $campaign->id)->exists()) {
            throw WaveRuleViolation::campaignAlreadyLinked();
        }

        return $campaign;
    }
}
