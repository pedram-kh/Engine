<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Boards\Services\BoardService;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Models\Creator;
use App\Modules\Strategy\Features\CreatorsScriptsEnabled;
use App\Modules\Strategy\Models\WaveCreator;
use Laravel\Pennant\Feature;

/**
 * PL-3.1 — fixtures for the creators specs: the strategy world with the sprint flag armed and a for-a-wave campaign linked to
 * the wave; applicants, invitees (assignment and card), and the URL helper.
 */
final class CreatorFixtures
{
    /**
     * @return array<string, mixed> a StrategyFixtures::world() result plus `campaign`
     */
    public static function world(bool $armed = true, bool $linked = true): array
    {
        $w = StrategyFixtures::world();
        if ($armed) {
            Feature::activate(CreatorsScriptsEnabled::NAME);
        }
        $campaign = WaveFixtures::waveCampaign($w['agency'], $w['brand']);
        if ($linked) {
            $w['wave']->forceFill(['campaign_id' => $campaign->id])->save();
            app(BoardService::class)->ensureBoard($campaign);
        }

        return $w + ['campaign' => $campaign];
    }

    /** An approved, discoverable creator who applied (status as given). */
    public static function applicant(Campaign $campaign, CampaignApplicationStatus $status = CampaignApplicationStatus::Pending, array $creator = []): Creator
    {
        $c = Creator::factory()->approved()->create($creator + ['is_discoverable' => true]);
        CampaignApplication::factory()->status($status)->create(['campaign_id' => $campaign->id, 'agency_id' => $campaign->agency_id, 'creator_id' => $c->id]);

        return $c;
    }

    /** An approved creator with a live assignment on the campaign and its board card (Invited column), status as given. */
    public static function invitee(Campaign $campaign, AssignmentStatus $status = AssignmentStatus::Invited, array $creator = []): Creator
    {
        $c = Creator::factory()->approved()->create($creator + ['is_discoverable' => true]);
        self::assign($campaign, $c, $status);

        return $c;
    }

    public static function assign(Campaign $campaign, Creator $creator, AssignmentStatus $status = AssignmentStatus::Invited): CampaignAssignment
    {
        $assignment = CampaignAssignment::factory()->create(['agency_id' => $campaign->agency_id, 'campaign_id' => $campaign->id, 'creator_id' => $creator->id, 'status' => $status]);
        app(BoardService::class)->forCampaign($campaign);

        return $assignment;
    }

    public static function card(CampaignAssignment $assignment): BoardCard
    {
        return BoardCard::query()->withoutGlobalScopes()->where('assignment_id', $assignment->id)->with('column')->firstOrFail();
    }

    /** @param  array<string, mixed>  $w */
    public static function slot(array $w, Creator $creator, string $market = 'SK', array $extra = []): WaveCreator
    {
        return WaveCreator::factory()->create($extra + ['wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id, 'market_id' => $w['mkid'][$market], 'creator_id' => $creator->id]);
    }

    /** @param  array<string, mixed>  $w */
    public static function slots(array $w, int $n, string $market = 'SK'): void
    {
        for ($i = 0; $i < $n; $i++) {
            WaveCreator::factory()->create(['wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id, 'market_id' => $w['mkid'][$market]]);
        }
    }

    /** @param  array<string, mixed>  $w */
    public static function url(array $w, string $suffix = ''): string
    {
        return StrategyFixtures::u($w, 'creators'.($suffix !== '' ? '/'.$suffix : ''));
    }

    public static function lastAudit(string $action): AuditLog
    {
        return AuditLog::query()->where('action', $action)->latest('id')->firstOrFail();
    }

    public static function meta(string $action, string $key): mixed
    {
        return (self::lastAudit($action)->metadata ?? [])[$key] ?? null;
    }

    public static function fresh(WaveCreator $slot): WaveCreator
    {
        return WaveCreator::query()->findOrFail($slot->id);
    }
}
