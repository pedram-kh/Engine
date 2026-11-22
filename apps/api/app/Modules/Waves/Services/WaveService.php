<?php

declare(strict_types=1);

namespace App\Modules\Waves\Services;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Boards\Services\BoardService;
use App\Modules\Brands\Enums\BrandStatus;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\CampaignObjective;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Enums\CampaignType;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Contracts\WaveStrategyState;
use App\Modules\Waves\Enums\WaveStatus;
use App\Modules\Waves\Models\Wave;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Wave writes (PL-1.1, amended by PL-1.2). The rules live here, not in the controller:
 *
 *   - an archived brand is read-only (create AND edit);
 *   - a linked campaign must be a FOR-A-WAVE campaign (P2) of this agency AND this brand, not soft-deleted and not
 *     Cancelled; every "no" is the SAME `wave.campaign_not_available`, so a response never reveals whether a
 *     foreign, deleted, cancelled or normal campaign exists;
 *   - one wave per campaign: the partial unique index is the only guard; its violation is mapped to
 *     `wave.campaign_already_linked` (there is no pre-check);
 *   - the link can be set, changed or removed while the campaign's link is not locked (P5, Q3: locked while a card
 *     sits in a working column); then `wave.campaign_link_locked`. The wave row is locked FOR UPDATE first, and a
 *     card move that can change the lock takes the same lock, so the two cannot interleave;
 *   - the wave side and the campaign side go through the SAME methods here: one rule path.
 */
final class WaveService
{
    public function __construct(
        private readonly WaveCampaignLink $links,
        private readonly WaveStepProvisioner $provisioner,
        private readonly WaveMarketService $markets,
        private readonly WaveStrategyState $strategy,
    ) {}

    /**
     * @param  array<string, mixed>  $data  name, go_live_on, markets (list of {name, code?, countries}), campaign_id (already validated)
     */
    public function create(Agency $agency, Brand $brand, User $actor, array $data): Wave
    {
        $this->assertBrandWritable($brand);

        $campaign = $this->linkableCampaign($agency, $brand, $data['campaign_id'] ?? null);

        try {
            $wave = DB::transaction(fn (): Wave => $this->insertWave($agency, $brand, $actor, $data, $campaign));
        } catch (UniqueConstraintViolationException) {
            throw WaveRuleViolation::campaignAlreadyLinked();
        }

        $this->logCreated($wave, $campaign?->ulid);

        return $wave;
    }

    /**
     * PL-1.2 (P3) — "Create a campaign for this wave now". ONE transaction: the for-a-wave campaign (draft, posting
     * off, objective ugc, budget 0 in the agency's default currency: the rest is filled in later), its nine-column
     * board, and the wave linked to it. Any failure rolls back all three. Audit rows are written after commit.
     *
     * @param  array<string, mixed>  $data  name, go_live_on, markets, new_campaign.name (already validated)
     */
    public function createWithNewCampaign(Agency $agency, Brand $brand, User $actor, array $data): Wave
    {
        $this->assertBrandWritable($brand);

        [$wave, $campaign] = DB::transaction(function () use ($agency, $brand, $actor, $data): array {
            $campaign = Campaign::query()->create([
                'agency_id' => $agency->id,
                'brand_id' => $brand->id,
                'created_by_user_id' => $actor->id,
                'name' => trim($data['new_campaign']['name']),
                'objective' => CampaignObjective::Ugc,
                'status' => CampaignStatus::Draft,
                'budget_minor_units' => 0,
                'budget_currency' => strtoupper($agency->default_currency),
                'creator_posts_content' => false,
                'campaign_type' => CampaignType::ForWave,
            ]);

            app(BoardService::class)->ensureBoard($campaign);

            return [$this->insertWave($agency, $brand, $actor, $data, $campaign), $campaign];
        });

        Audit::log(
            action: AuditAction::CampaignCreated,
            subject: $campaign,
            // Literal keys on purpose (AuditPayloadKeysGuardTest reads them statically).
            after: [
                'name' => $campaign->name,
                'objective' => $campaign->objective->value,
                'status' => $campaign->status->value,
                'budget_minor_units' => $campaign->budget_minor_units,
                'budget_currency' => $campaign->budget_currency,
                'brand_id' => $campaign->brand_id,
                'agency_id' => $campaign->agency_id,
                'creator_posts_content' => $campaign->creator_posts_content,
                'campaign_type' => $campaign->campaign_type->value,
                'listed_on_jobs_board' => $campaign->listed_on_jobs_board,
            ],
        );
        $this->logCreated($wave, $campaign->ulid);

        return $wave;
    }

    /**
     * @param  array<string, mixed>  $data  A partial body: name, go_live_on, markets, campaign_id.
     */
    public function update(Agency $agency, Brand $brand, Wave $wave, array $data): Wave
    {
        $this->assertBrandWritable($brand);

        try {
            // The whole read-decide-write runs inside one transaction on a ROW-LOCKED copy of the wave, so two
            // concurrent edits are serialised: the second one re-reads the first one's link and re-applies the rule
            // against it (PL-1.1 review fix F2). The caller's $wave may be stale by the time the lock is granted.
            [$wave, $before, $afterCampaignUlid, $changed, $marketsResult] = DB::transaction(function () use ($agency, $brand, $wave, $data): array {
                $locked = Wave::query()->whereKey($wave->id)->lockForUpdate()->firstOrFail();

                $before = $this->snapshot($locked);
                $afterCampaignUlid = $before['campaign_id'];

                if (array_key_exists('campaign_id', $data)) {
                    $afterCampaignUlid = $this->applyCampaign($locked, $data['campaign_id'], $agency, $brand, $before['campaign_id']);
                }

                if (array_key_exists('name', $data)) {
                    $locked->name = trim((string) $data['name']);
                }
                if (array_key_exists('go_live_on', $data)) {
                    $locked->go_live_on = $data['go_live_on'];
                }
                $marketsResult = null;
                if (array_key_exists('markets', $data)) {
                    // PL-2.4: the markets are rows. The replacement runs HERE, on the locked row, so it is serialised
                    // with every strategy write (they lock this same row first): version check (Q12), the rules, the
                    // PL-2.1 refusal of a market that still has personas, then the writes.
                    // PL-2.5: inside the strategy choke point, so a market added or removed moves the wave-level state of the
                    // per-market steps in the same transaction (a plain closure run while strategy is OFF).
                    $marketsResult = $this->strategy->around($locked, Auth::user(), fn (): array => $this->markets->replace(
                        $locked,
                        array_values((array) $data['markets']),
                        (string) ($data['markets_version'] ?? ''),
                        isset($data['confirm_notes_deleted']) ? (int) $data['confirm_notes_deleted'] : null,
                    ));
                }

                $changed = $locked->isDirty() || ($marketsResult['changed'] ?? false);
                if ($locked->isDirty()) {
                    $locked->save();
                } elseif ($changed) {
                    $locked->touch();
                }

                return [$locked, $before, $afterCampaignUlid, $changed, $marketsResult];
            });
        } catch (UniqueConstraintViolationException) {
            throw WaveRuleViolation::campaignAlreadyLinked();
        }

        if (! $changed) {
            return $wave;
        }

        $this->logUpdated($wave, $before, $afterCampaignUlid, $marketsResult);
        if ($marketsResult !== null && $marketsResult['persona_codes']['markets'] !== []) {
            $wave->personaCodes = [
                'renamed' => $marketsResult['persona_codes']['renamed'],
                'skipped' => $marketsResult['persona_codes']['skipped'],
                'kept_custom' => $marketsResult['persona_codes']['kept_custom'],
            ];
        }

        return $wave;
    }

    /**
     * PL-1.2 (P4) — link this campaign to a wave of its brand from the CAMPAIGN side. If the campaign is already
     * linked to another wave, that link is changed (the old wave is freed) under the same lock rule. Returns the
     * linked wave. Every refusal is the wave side's own: not available, already linked, locked, brand archived.
     */
    public function linkFromCampaign(Agency $agency, Campaign $campaign, string $waveUlid): Wave
    {
        $brand = Brand::query()->withTrashed()->findOrFail($campaign->brand_id);
        $this->assertBrandWritable($brand);

        $audits = [];

        try {
            $target = DB::transaction(function () use ($agency, $brand, $campaign, $waveUlid, &$audits): Wave {
                $targetId = Wave::query()
                    ->where('ulid', $waveUlid)->where('agency_id', $agency->id)->where('brand_id', $brand->id)
                    ->value('id');
                $currentId = Wave::query()->where('campaign_id', $campaign->id)->value('id');

                if ($targetId === null) {
                    throw WaveRuleViolation::campaignNotAvailable();
                }

                // Both rows, locked in id order so two campaign-side calls cannot deadlock each other.
                $ids = array_values(array_unique(array_filter([(int) $targetId, $currentId === null ? null : (int) $currentId])));
                sort($ids);
                $rows = Wave::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

                $target = $rows->get((int) $targetId) ?? throw WaveRuleViolation::campaignNotAvailable();
                $current = $currentId === null ? null : $rows->get((int) $currentId);

                if ($current !== null && $current->id === $target->id) {
                    return $target;
                }

                if ($current !== null) {
                    $audits[] = $this->detach($current, $agency, $brand);
                }

                $before = $this->snapshot($target);
                $after = $this->applyCampaign($target, $campaign->ulid, $agency, $brand, $before['campaign_id']);
                $target->save();
                $audits[] = [$target, $before, $after];

                return $target;
            });
        } catch (UniqueConstraintViolationException) {
            throw WaveRuleViolation::campaignAlreadyLinked();
        }

        foreach ($audits as [$wave, $before, $afterCampaignUlid]) {
            $this->logUpdated($wave, $before, $afterCampaignUlid);
        }

        return $target;
    }

    /**
     * PL-1.2 (P4, P5) — remove this campaign's link from the campaign side. A campaign with no wave is a no-op.
     */
    public function unlinkFromCampaign(Agency $agency, Campaign $campaign): void
    {
        $brand = Brand::query()->withTrashed()->findOrFail($campaign->brand_id);
        $this->assertBrandWritable($brand);

        $audit = DB::transaction(function () use ($agency, $brand, $campaign): ?array {
            $current = Wave::query()->where('campaign_id', $campaign->id)->lockForUpdate()->first();

            return $current === null ? null : $this->detach($current, $agency, $brand);
        });

        if ($audit !== null) {
            $this->logUpdated($audit[0], $audit[1], $audit[2]);
        }
    }

    /**
     * Remove the link of an already-locked wave row (the lock rule applies) and save it.
     *
     * @return array{0: Wave, 1: array<string, mixed>, 2: string|null}
     */
    private function detach(Wave $locked, Agency $agency, Brand $brand): array
    {
        $before = $this->snapshot($locked);
        $after = $this->applyCampaign($locked, null, $agency, $brand, $before['campaign_id']);
        $locked->save();

        return [$locked, $before, $after];
    }

    /**
     * The one link rule, on a ROW-LOCKED wave. Sets `campaign_id` on the model (the caller saves) and returns the
     * campaign ulid the wave ends up with. Asking for what it already has is a no-op. Changing or removing an
     * existing link is refused while the campaign's link is locked (a card in a working column, read NOW).
     *
     * @return string|null the campaign ulid after the change
     */
    private function applyCampaign(Wave $locked, ?string $requestedUlid, Agency $agency, Brand $brand, ?string $beforeUlid): ?string
    {
        if ($requestedUlid === $beforeUlid) {
            return $beforeUlid;
        }

        if ($locked->campaign_id !== null && $this->links->isLocked($locked->campaign_id)) {
            throw WaveRuleViolation::campaignLinkLocked();
        }

        $new = $this->linkableCampaign($agency, $brand, $requestedUlid);
        $locked->campaign_id = $new?->id;

        return $new?->ulid;
    }

    /**
     * @return array{name: string, go_live_on: string, campaign_id: string|null, status: string}
     */
    private function snapshot(Wave $wave): array
    {
        return [
            'name' => $wave->name,
            'go_live_on' => $wave->go_live_on->toDateString(),
            'campaign_id' => $wave->campaign_id === null
                ? null
                : Campaign::query()->withTrashed()->whereKey($wave->campaign_id)->value('ulid'),
            'status' => $wave->status->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function insertWave(Agency $agency, Brand $brand, User $actor, array $data, ?Campaign $campaign): Wave
    {
        $wave = Wave::query()->create([
            'agency_id' => $agency->id,
            'brand_id' => $brand->id,
            'campaign_id' => $campaign?->id,
            'name' => trim((string) $data['name']),
            'go_live_on' => $data['go_live_on'],
            'status' => WaveStatus::Draft,
            'created_by_user_id' => $actor->id,
        ]);

        // PL-2.4 — its markets, in the SAME transaction (a rule or key refusal rolls the wave back too).
        $this->markets->createFor($wave, array_values((array) $data['markets']));

        // PL-1.3 (T1) — a new wave starts with its ready-made steps, in the SAME transaction as the wave: a failure
        // rolls back both. Callers run this inside DB::transaction.
        $this->provisioner->ensure($wave);

        return $wave;
    }

    private function logCreated(Wave $wave, ?string $campaignUlid): void
    {
        $summary = $this->markets->currentSummary($wave);

        Audit::log(
            action: AuditAction::WaveCreated,
            subject: $wave,
            // Literal keys on purpose: AuditPayloadKeysGuardTest reads them statically and Erasure decides each.
            after: [
                'name' => $wave->name,
                'go_live_on' => $wave->go_live_on->toDateString(),
                'markets' => $summary['markets'],
                'market_countries' => $summary['market_countries'],
                'campaign_id' => $campaignUlid,
                'status' => $wave->status->value,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array{changed: bool, before: array{markets: list<string>, market_countries: array<string, list<string>>}, after: array{markets: list<string>, market_countries: array<string, list<string>>, markets_renamed: list<string>}, persona_codes: array{renamed: int, skipped: int, kept_custom: int, markets: list<string>}, removed: array{completions: int, notes: int}}|null  $marketsResult  null when the markets were not part of the edit
     */
    private function logUpdated(Wave $wave, array $before, ?string $afterCampaignUlid, ?array $marketsResult = null): void
    {
        if ($marketsResult === null) {
            $current = $this->markets->currentSummary($wave);
            $marketsBefore = $current;
            $marketsAfter = $current + ['markets_renamed' => []];
            $personaCodes = ['renamed' => 0, 'skipped' => 0, 'kept_custom' => 0, 'markets' => []];
            $removed = ['completions' => 0, 'notes' => 0];
        } else {
            $personaCodes = $marketsResult['persona_codes'];
            $removed = $marketsResult['removed'];
            $marketsBefore = $marketsResult['before'];
            $marketsAfter = $marketsResult['after'];
        }

        Audit::log(
            action: AuditAction::WaveUpdated,
            subject: $wave,
            // Literal keys on purpose (see logCreated()). Market NAMES are free text and never go in a payload: the
            // markets are ulids, their countries ISO codes, and `markets_renamed` the ulids whose name or code changed.
            before: [
                'name' => $before['name'],
                'go_live_on' => $before['go_live_on'],
                'markets' => $marketsBefore['markets'],
                'market_countries' => $marketsBefore['market_countries'],
                'campaign_id' => $before['campaign_id'],
                'status' => $before['status'],
            ],
            after: [
                'name' => $wave->name,
                'go_live_on' => $wave->go_live_on->toDateString(),
                'markets' => $marketsAfter['markets'],
                'market_countries' => $marketsAfter['market_countries'],
                'markets_renamed' => $marketsAfter['markets_renamed'],
                'persona_codes_renamed' => $personaCodes['renamed'],
                'persona_codes_skipped' => $personaCodes['skipped'],
                'persona_codes_markets' => $personaCodes['markets'],
                'completions_removed' => $removed['completions'],
                'notes_removed' => $removed['notes'],
                'campaign_id' => $afterCampaignUlid,
                'status' => $wave->status->value,
            ],
        );
    }

    private function assertBrandWritable(Brand $brand): void
    {
        if ($brand->trashed() || $brand->status === BrandStatus::Archived) {
            throw WaveRuleViolation::brandArchived();
        }
    }

    /**
     * Resolve the campaign a wave may link to, or null for "no link". Throws `campaign_not_available` for anything
     * that is not this agency's, this brand's, FOR-A-WAVE (P2), live and not cancelled. "Already has a wave" is NOT
     * checked here: the partial unique index plus the exception mapping is the single path to
     * `wave.campaign_already_linked`.
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
            ->where('campaign_type', CampaignType::ForWave->value)
            ->where('status', '!=', CampaignStatus::Cancelled->value)
            ->first();

        if ($campaign === null) {
            throw WaveRuleViolation::campaignNotAvailable();
        }

        return $campaign;
    }
}
