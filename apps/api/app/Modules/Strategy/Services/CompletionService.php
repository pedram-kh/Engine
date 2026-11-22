<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Models\WaveMatrixCell;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WaveStrategyCompletion;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Mark as complete" and "Reopen" (PL-2.5, K1) for USPs (per wave), Personas and Matrix (per market). Concepts has none
 * (it is complete by itself when every concept is approved).
 *
 * A mark is refused when the step has no data at all (Q1, 422 `strategy.nothing_to_complete`); the minimum warnings
 * (under four USPs, under two personas, cells not set) never block and are returned for the person to see. A mark is
 * idempotent. Both writes run through the tracker choke point, so the tracker row moves in the same transaction.
 */
final class CompletionService
{
    /**
     * @return list<array<string, mixed>> the live warnings at the time of the mark
     */
    public function mark(Brand $brand, Wave $wave, User $actor, string $step, ?string $marketUlid): array
    {
        StrategyGuard::assertBrandWritable($brand);

        return StrategyTrackerSync::transaction($wave, $actor, [$step], function () use ($wave, $actor, $step, $marketUlid): array {
            $locked = StrategyGuard::lockWave($wave);
            $market = $this->scope($locked, $step, $marketUlid);

            if ($this->dataCount($locked, $step, $market) === 0) {
                throw StrategyRuleViolation::nothingToComplete();
            }

            $existing = $this->query($locked, $step, $market)->first();
            if ($existing === null) {
                $mark = WaveStrategyCompletion::query()->create([
                    'agency_id' => $locked->agency_id,
                    'wave_id' => $locked->id,
                    'step' => $step,
                    'market_id' => $market?->id,
                    'completed_by_user_id' => $actor->id,
                    'completed_at' => now(),
                ]);

                Audit::log(
                    action: AuditAction::WaveStrategyCompleted,
                    actor: $actor,
                    subject: $mark,
                    // Literal keys on purpose (AuditPayloadKeysGuardTest reads them statically).
                    metadata: ['wave' => $locked->ulid, 'step' => $step, 'market' => $market?->ulid],
                    agencyId: $locked->agency_id,
                );
            }

            return StrategyStepState::warnings($locked, $step, $market?->id);
        });
    }

    public function reopen(Brand $brand, Wave $wave, User $actor, string $step, ?string $marketUlid): void
    {
        StrategyGuard::assertBrandWritable($brand);

        StrategyTrackerSync::transaction($wave, $actor, [$step], function () use ($wave, $actor, $step, $marketUlid): void {
            $locked = StrategyGuard::lockWave($wave);
            $market = $this->scope($locked, $step, $marketUlid);
            $mark = $this->query($locked, $step, $market)->first();
            if ($mark === null) {
                return;
            }
            $mark->delete();

            Audit::log(
                action: AuditAction::WaveStrategyReopened,
                actor: $actor,
                subject: $locked,
                metadata: ['wave' => $locked->ulid, 'step' => $step, 'market' => $market?->ulid, 'reason' => 'manual'],
                agencyId: $locked->agency_id,
            );
        });
    }

    /** The market of the mark: none for USPs (and one is refused), a market of this wave for the others. */
    private function scope(Wave $wave, string $step, ?string $marketUlid): ?WaveMarket
    {
        if (! in_array($step, StrategyStepState::MARKABLE, true)) {
            throw StrategyRuleViolation::conflict404();
        }
        if ($step === StrategyStepState::USPS) {
            if ($marketUlid !== null) {
                throw StrategyRuleViolation::stepMarket(false);
            }

            return null;
        }
        if ($marketUlid === null) {
            throw StrategyRuleViolation::stepMarket(true);
        }

        return PersonaService::assertMarketInWave($wave, $marketUlid);
    }

    private function dataCount(Wave $wave, string $step, ?WaveMarket $market): int
    {
        return match ($step) {
            StrategyStepState::USPS => WaveUsp::query()->where('wave_id', $wave->id)->count(),
            StrategyStepState::PERSONAS => WavePersona::query()->where('wave_id', $wave->id)->where('market_id', $market?->id)->count(),
            default => WaveMatrixCell::query()->where('wave_id', $wave->id)->where('market_id', $market?->id)->count(),
        };
    }

    /**
     * @return Builder<WaveStrategyCompletion>
     */
    private function query(Wave $wave, string $step, ?WaveMarket $market)
    {
        $q = WaveStrategyCompletion::query()->where('wave_id', $wave->id)->where('step', $step);

        return $market === null ? $q->whereNull('market_id') : $q->where('market_id', $market->id);
    }
}
