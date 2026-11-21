<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Enums\ConceptStatus;
use App\Modules\Strategy\Models\WaveConcept;
use App\Modules\Strategy\Models\WaveConceptDecision;
use App\Modules\Waves\Models\Wave;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The client's decision on a concept, recorded by an admin or manager on the client's behalf (PL-2.3, ruling C5, Q13).
 * Append-only: a new decision is a new row and replaces the status; every decision is kept. Approved needs the client's
 * name (note optional, no comment); Changes requested needs the name and the client's comment. The audit row says that a
 * decision was made, its status and two flags; never the name, the note or the comment.
 */
final class ConceptDecisionService
{
    public function __construct(private readonly ConceptService $concepts) {}

    /**
     * @param  array{status: string, client_name: string, note?: string|null, comment?: string|null}  $data  cleaned
     */
    public function record(Brand $brand, Wave $wave, string $conceptUlid, User $actor, array $data): WaveConceptDecision
    {
        StrategyGuard::assertBrandWritable($brand);

        $status = ConceptStatus::tryFrom($data['status']);
        if ($status === null || $status === ConceptStatus::Awaiting) {
            throw StrategyRuleViolation::decisionInvalid('A decision is Approved or Changes requested.');
        }
        $name = $data['client_name'] ?? '';
        $note = $data['note'] ?? null;
        $comment = $data['comment'] ?? null;
        if ($name === '') {
            throw StrategyRuleViolation::decisionInvalid("The client's name is required.");
        }
        if ($status === ConceptStatus::Approved && $comment !== null) {
            throw StrategyRuleViolation::decisionInvalid('An approval carries a note, not a comment.');
        }
        if ($status === ConceptStatus::ChangesRequested && ($comment === null || $comment === '')) {
            throw StrategyRuleViolation::decisionInvalid("The client's comment is required when changes are requested.");
        }
        if ($status === ConceptStatus::ChangesRequested) {
            $note = null;
        }

        return DB::transaction(function () use ($wave, $conceptUlid, $actor, $status, $name, $note, $comment): WaveConceptDecision {
            $locked = StrategyGuard::lockWave($wave);
            $concept = $this->concepts->lockedConcept($locked, $conceptUlid);

            $decision = WaveConceptDecision::query()->create([
                'agency_id' => $locked->agency_id,
                'wave_id' => $locked->id,
                'market_id' => $concept->market_id,
                'concept_id' => $concept->id,
                'status' => $status,
                'client_name' => $name,
                'note' => $note,
                'comment' => $comment,
                'source' => 'agency',
                'recorded_by_user_id' => $actor->id,
                'recorded_at' => now(),
            ]);
            $concept->status = $status;
            $concept->save();

            Audit::log(
                action: AuditAction::WaveConceptDecided,
                actor: $actor,
                subject: $concept,
                // Literal keys. Never the client's name, note or comment (they are a third party's words).
                metadata: ['wave' => $locked->ulid, 'concept' => $concept->ulid, 'market' => $concept->market->ulid, 'status' => $status->value, 'has_note' => $note !== null, 'has_comment' => $comment !== null],
                agencyId: $locked->agency_id,
            );

            return $decision;
        });
    }

    /**
     * Every decision of a concept, newest first (the history; the card shows only the latest).
     *
     * @return Collection<int, WaveConceptDecision>
     */
    public function history(Wave $wave, string $conceptUlid): Collection
    {
        $concept = $this->concepts->find($wave, $conceptUlid);

        return WaveConceptDecision::query()->where('concept_id', $concept->id)->with('recordedBy:id,ulid,name')->orderByDesc('id')->get();
    }

    public static function latest(WaveConcept $concept): ?WaveConceptDecision
    {
        return WaveConceptDecision::query()->where('concept_id', $concept->id)->with('recordedBy:id,ulid,name')->orderByDesc('id')->first();
    }
}
