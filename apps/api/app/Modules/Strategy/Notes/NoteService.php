<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Notes;

use App\Core\Tenancy\CurrentAgencyRole;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Features\CreatorsScriptsEnabled;
use App\Modules\Strategy\Models\WaveStrategyNote;
use App\Modules\Strategy\Services\PersonaService;
use App\Modules\Strategy\Services\StrategyGuard;
use App\Modules\Strategy\Services\StrategyRuleViolation;
use App\Modules\Strategy\Services\StrategyStepState;
use App\Modules\Strategy\Services\StrategyText;
use App\Modules\Strategy\Services\WaveCreatorStepState;
use App\Modules\Strategy\Services\WaveScriptStepState;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * INTERNAL notes on a strategy step (PL-2.5, K4). One thread per step per market (USPs: one per wave). Plain text
 * (`StrategyText`), 1 to 2000 characters, 100 notes per thread. Edit: the author only. Delete: the author, admin or manager.
 *
 * A note is never serialised for a client or a creator: this namespace is the only place (with the market-removal guard)
 * that touches {@see WaveStrategyNote}; `StrategyNoteIsolationTest` fails the build otherwise (plan section 1.7).
 *
 * A note write does not change any step's state, so it does not go through the tracker choke point; it still takes the
 * wave lock first (the order every strategy write uses), which also serialises it with a market removal.
 */
final class NoteService
{
    public const BODY_MAX = 2000;

    public const THREAD_MAX = 100;

    /**
     * @return Collection<int, WaveStrategyNote>
     */
    public function thread(Wave $wave, string $step, ?string $marketUlid): Collection
    {
        $market = $this->scope($wave, $step, $marketUlid);

        return $this->query($wave, $step, $market?->id)->with('author')->orderBy('created_at')->orderBy('id')->get();
    }

    public function create(Brand $brand, Wave $wave, User $actor, string $step, ?string $marketUlid, string $body): WaveStrategyNote
    {
        StrategyGuard::assertBrandWritable($brand);

        return DB::transaction(function () use ($wave, $actor, $step, $marketUlid, $body): WaveStrategyNote {
            $locked = StrategyGuard::lockWave($wave);
            $market = $this->scope($locked, $step, $marketUlid);
            $text = $this->body($body);

            if ($this->query($locked, $step, $market?->id)->count() >= self::THREAD_MAX) {
                throw StrategyRuleViolation::noteThreadFull();
            }

            $note = WaveStrategyNote::query()->create([
                'agency_id' => $locked->agency_id,
                'wave_id' => $locked->id,
                'step' => $step,
                'market_id' => $market?->id,
                'author_user_id' => $actor->id,
                'body' => $text,
            ]);

            Audit::log(
                action: AuditAction::WaveStrategyNoteCreated,
                actor: $actor,
                subject: $note,
                // Literal keys on purpose (AuditPayloadKeysGuardTest reads them statically). The length, never the text.
                metadata: ['wave' => $locked->ulid, 'step' => $step, 'market' => $market?->ulid, 'length' => mb_strlen($text)],
                agencyId: $locked->agency_id,
            );

            return $note;
        });
    }

    public function update(Brand $brand, Wave $wave, User $actor, string $noteUlid, string $body): WaveStrategyNote
    {
        StrategyGuard::assertBrandWritable($brand);

        return DB::transaction(function () use ($wave, $actor, $noteUlid, $body): WaveStrategyNote {
            $locked = StrategyGuard::lockWave($wave);
            $note = $this->find($locked, $noteUlid);
            if ($note->author_user_id === null || $note->author_user_id !== $actor->id) {
                throw StrategyRuleViolation::noteForbidden();
            }
            $text = $this->body($body);
            if ($text === $note->body) {
                return $note;
            }

            $note->body = $text;
            $note->edited_at = now();
            $note->save();

            Audit::log(
                action: AuditAction::WaveStrategyNoteUpdated,
                actor: $actor,
                subject: $note,
                metadata: ['wave' => $locked->ulid, 'step' => $note->step, 'market' => $note->market?->ulid, 'length' => mb_strlen($text)],
                agencyId: $locked->agency_id,
            );

            return $note;
        });
    }

    public function delete(Brand $brand, Wave $wave, User $actor, string $noteUlid): void
    {
        StrategyGuard::assertBrandWritable($brand);

        DB::transaction(function () use ($wave, $actor, $noteUlid): void {
            $locked = StrategyGuard::lockWave($wave);
            $note = $this->find($locked, $noteUlid);
            $own = $note->author_user_id !== null && $note->author_user_id === $actor->id;
            if (! $own && ! CurrentAgencyRole::canManage($actor)) {
                throw StrategyRuleViolation::noteForbidden();
            }

            $length = mb_strlen($note->body);
            $marketUlid = $note->market?->ulid;
            $step = $note->step;
            $note->delete();

            Audit::log(
                action: AuditAction::WaveStrategyNoteDeleted,
                actor: $actor,
                subject: $locked,
                metadata: ['wave' => $locked->ulid, 'step' => $step, 'market' => $marketUlid, 'length' => $length],
                agencyId: $locked->agency_id,
            );
        });
    }

    /**
     * Note counts for the summary (agency response only): USPs one number, the other steps one per market ulid.
     *
     * @return array{usps: int, personas: array<string, int>, matrix: array<string, int>, concepts: array<string, int>}
     */
    public static function counts(Wave $wave): array
    {
        $usps = 0;
        $by = ['personas' => [], 'matrix' => [], 'concepts' => []];
        $ulids = WaveMarket::query()->where('wave_id', $wave->id)->pluck('ulid', 'id')->all();
        foreach (WaveStrategyNote::query()->where('wave_id', $wave->id)->selectRaw('step, market_id, count(*) as n')->groupBy('step', 'market_id')->get() as $row) {
            $step = (string) $row->getAttribute('step');
            $n = (int) $row->getAttribute('n');
            if ($step === StrategyStepState::USPS) {
                $usps = $n;

                continue;
            }
            $ulid = $ulids[(int) $row->getAttribute('market_id')] ?? null;
            if ($ulid !== null && isset($by[$step])) {
                $by[$step][(string) $ulid] = $n;
            }
        }

        return ['usps' => $usps, 'personas' => $by['personas'], 'matrix' => $by['matrix'], 'concepts' => $by['concepts']];
    }

    /**
     * PL-3.1: the notes of the `creators` step per market ulid (the step's "Notes (n)" button). A separate read, so the
     * PL-2.5 summary keeps its key set.
     *
     * @return array<string, int>
     */
    public static function creatorCounts(Wave $wave): array
    {
        $ulids = WaveMarket::query()->where('wave_id', $wave->id)->pluck('ulid', 'id')->all();
        $out = [];
        foreach (WaveStrategyNote::query()->where('wave_id', $wave->id)->where('step', WaveCreatorStepState::STEP)->selectRaw('market_id, count(*) as n')->groupBy('market_id')->get() as $row) {
            $ulid = $ulids[(int) $row->getAttribute('market_id')] ?? null;
            if ($ulid !== null) {
                $out[(string) $ulid] = (int) $row->getAttribute('n');
            }
        }

        return $out;
    }

    /**
     * PL-3.2a: the notes of one per-market step in one market (the scripts rail's "Notes (n)" button). A count only.
     */
    public static function countForMarketStep(Wave $wave, string $step, int $marketId): int
    {
        return WaveStrategyNote::query()->where('wave_id', $wave->id)->where('step', $step)->where('market_id', $marketId)->count();
    }

    /**
     * @param  list<int>  $marketIds
     * @return array<int, int> market id => notes, only markets with at least one
     */
    public static function countsByMarket(Wave $locked, array $marketIds): array
    {
        if ($marketIds === []) {
            return [];
        }

        /** @var array<int, int> $counts */
        $counts = WaveStrategyNote::query()
            ->where('wave_id', $locked->id)
            ->whereIn('market_id', $marketIds)
            ->selectRaw('market_id, count(*) as n')
            ->groupBy('market_id')
            ->orderBy('market_id')
            ->pluck('n', 'market_id')
            ->map(static fn (mixed $n): int => (int) $n)
            ->all();

        return $counts;
    }

    /**
     * @param  list<int>  $marketIds
     */
    public static function purgeMarkets(Wave $locked, array $marketIds): int
    {
        if ($marketIds === []) {
            return 0;
        }

        return WaveStrategyNote::query()->where('wave_id', $locked->id)->whereIn('market_id', $marketIds)->delete();
    }

    /**
     * @return Builder<WaveStrategyNote>
     */
    private function query(Wave $wave, string $step, ?int $marketId)
    {
        $q = WaveStrategyNote::query()->where('wave_id', $wave->id)->where('step', $step);

        return $marketId === null ? $q->whereNull('market_id') : $q->where('market_id', $marketId);
    }

    /** The thread's market: none for USPs (and a market is refused there), a market of this wave for the others. */
    private function scope(Wave $wave, string $step, ?string $marketUlid): ?WaveMarket
    {
        // PL-3.1: the per-market `creators` thread exists only while the sprint flag is on; off, it is a 404 like any other name.
        // PL-3.2a: the same for the per-market `scripts` thread (step 6).
        $steps = CreatorsScriptsEnabled::active() ? [...StrategyStepState::STEPS, WaveCreatorStepState::STEP, WaveScriptStepState::STEP] : StrategyStepState::STEPS;
        if (! in_array($step, $steps, true)) {
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

    private function find(Wave $wave, string $ulid): WaveStrategyNote
    {
        $note = WaveStrategyNote::query()->where('wave_id', $wave->id)->where('ulid', $ulid)->first();
        if ($note === null) {
            throw StrategyRuleViolation::conflict404();
        }

        return $note;
    }

    /** Plain text, 1 to 2000 characters after cleaning. */
    private function body(string $body): string
    {
        $text = StrategyText::clean($body);
        if ($text === null || mb_strlen($text) > self::BODY_MAX) {
            throw StrategyRuleViolation::noteBodyInvalid();
        }

        return $text;
    }
}
