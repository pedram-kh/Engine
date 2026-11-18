<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Boards\Models\BoardColumn;
use App\Modules\Boards\Services\BoardService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Features\WavesEnabled;
use Laravel\Pennant\Feature;

/**
 * PL-1.1 — shared fixtures for the wave and brand-file specs: one agency with an admin, a manager and a staff
 * user and a brand, plus a second, unrelated agency. The flag is armed by `armed()`.
 */
final class WaveFixtures
{
    /**
     * @return array{agency: Agency, brand: Brand, admin: User, manager: User, staff: User, otherAgency: Agency, otherBrand: Brand, otherAdmin: User}
     */
    public static function world(): array
    {
        $agency = Agency::factory()->createOne();
        $brand = Brand::factory()->forAgency($agency->id)->createOne();
        $other = Agency::factory()->createOne();

        return [
            'agency' => $agency,
            'brand' => $brand,
            'admin' => User::factory()->agencyAdmin($agency)->createOne(),
            'manager' => User::factory()->agencyManager($agency)->createOne(),
            'staff' => User::factory()->agencyStaff($agency)->createOne(),
            'otherAgency' => $other,
            'otherBrand' => Brand::factory()->forAgency($other->id)->createOne(),
            'otherAdmin' => User::factory()->agencyAdmin($other)->createOne(),
        ];
    }

    public static function armed(): void
    {
        Feature::activate(WavesEnabled::NAME);
    }

    /** A NORMAL campaign (the default type; PL-1.2 P2: it can no longer be linked to a wave). */
    public static function campaign(Agency $agency, Brand $brand, string $status = 'draft', string $name = ''): Campaign
    {
        return Campaign::factory()->createOne(array_filter([
            'agency_id' => $agency->id,
            'brand_id' => $brand->id,
            'status' => $status,
            'name' => $name !== '' ? $name : null,
        ]));
    }

    /** PL-1.2 — a FOR-A-WAVE campaign: the only kind a wave can link to. */
    public static function waveCampaign(Agency $agency, Brand $brand, string $status = 'draft', string $name = ''): Campaign
    {
        return Campaign::factory()->forWave()->createOne(array_filter([
            'agency_id' => $agency->id,
            'brand_id' => $brand->id,
            'status' => $status,
            'name' => $name !== '' ? $name : null,
        ]));
    }

    /**
     * PL-1.2 (Q3) — put a card of an Accepted creator in the named stage column of the campaign's wave board
     * (provisioning the board if needed). Raw column write: the lock reads positions, not how a card got there.
     */
    public static function cardIn(Campaign $campaign, string $stage): BoardCard
    {
        $board = app(BoardService::class)->ensureBoard($campaign);
        $assignment = CampaignAssignment::factory()->create([
            'agency_id' => $campaign->agency_id, 'campaign_id' => $campaign->id, 'status' => AssignmentStatus::Accepted,
        ]);
        app(BoardService::class)->forCampaign($campaign);
        $card = BoardCard::query()->where('assignment_id', $assignment->id)->firstOrFail();
        $card->update(['column_id' => $board->columns()->where('stage_key', $stage)->firstOrFail()->id]);

        return $card;
    }

    /** The column of a card's board that carries the stage key. */
    public static function stageColumn(BoardCard $card, string $stage): BoardColumn
    {
        return BoardColumn::query()->where('board_id', $card->board_id)->where('stage_key', $stage)->firstOrFail();
    }

    /** The campaign ulid an audit row records on one side of a wave change (null when none). */
    public static function auditCampaign(?AuditLog $audit, string $side): ?string
    {
        $payload = $audit === null ? null : ($side === 'before' ? $audit->before : $audit->after);
        $value = is_array($payload) ? ($payload['campaign_id'] ?? null) : null;

        return is_string($value) ? $value : null;
    }

    /** @return list<string> */
    public static function auditKeys(?AuditLog $audit): array
    {
        $after = $audit === null ? null : $audit->after;

        return array_map('strval', array_keys(is_array($after) ? $after : []));
    }

    public static function wavesUrl(Agency $agency, Brand $brand, string $suffix = ''): string
    {
        return "/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}/waves".($suffix !== '' ? '/'.$suffix : '');
    }

    /** @return array<string, mixed> */
    public static function body(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Wave 7',
            'go_live_on' => '2026-12-01',
            'markets' => ['SK', 'RO'],
        ], $overrides);
    }
}
