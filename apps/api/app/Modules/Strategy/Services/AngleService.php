<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Enums\AngleOrigin;
use App\Modules\Strategy\Models\WaveMatrixCell;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WavePersonaAngle;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Waves\Models\Wave;
use Illuminate\Support\Facades\DB;

/**
 * A persona's angles: one line linking a persona to a USP of the SAME wave (PL-2.1). The persona and the USP are looked
 * up through the wave (404 otherwise) and the composite foreign keys make the same-wave rule true in the database. One
 * angle per persona per USP. Written by hand (`origin = manual`) or synced from the matrix (`origin = matrix`, read-only here). Audit rows carry ids, never the line.
 */
final class AngleService
{
    public function add(Brand $brand, Wave $wave, string $personaUlid, User $actor, string $uspUlid, string $explanation): WavePersonaAngle
    {
        StrategyGuard::assertBrandWritable($brand);

        return DB::transaction(function () use ($wave, $personaUlid, $actor, $uspUlid, $explanation): WavePersonaAngle {
            $locked = StrategyGuard::lockWave($wave);
            $persona = $this->persona($locked, $personaUlid);
            $usp = WaveUsp::query()->where('ulid', $uspUlid)->where('wave_id', $locked->id)->where('agency_id', $locked->agency_id)->firstOrFail();

            if (WavePersonaAngle::query()->where('persona_id', $persona->id)->where('usp_id', $usp->id)->exists()) {
                throw StrategyRuleViolation::angleAlreadyLinked();
            }

            $angle = WavePersonaAngle::query()->create([
                'agency_id' => $locked->agency_id,
                'wave_id' => $locked->id,
                'persona_id' => $persona->id,
                'usp_id' => $usp->id,
                'explanation' => $explanation,
                'origin' => AngleOrigin::Manual,
            ]);

            Audit::log(
                action: AuditAction::WavePersonaAngleAdded,
                actor: $actor,
                subject: $angle,
                metadata: ['wave' => $locked->ulid, 'persona' => $persona->ulid, 'angle' => $angle->ulid, 'usp' => $usp->ulid, 'market' => $persona->market],
                agencyId: $locked->agency_id,
            );

            return $angle->load(['usp', 'persona']);
        });
    }

    public function update(Brand $brand, Wave $wave, string $personaUlid, string $angleUlid, User $actor, string $explanation): WavePersonaAngle
    {
        StrategyGuard::assertBrandWritable($brand);

        return DB::transaction(function () use ($wave, $personaUlid, $angleUlid, $actor, $explanation): WavePersonaAngle {
            $locked = StrategyGuard::lockWave($wave);
            $persona = $this->persona($locked, $personaUlid);
            $angle = $this->angle($persona, $angleUlid);
            if ($angle->origin === AngleOrigin::Matrix) {
                throw StrategyRuleViolation::angleSyncedReadOnly();
            }

            $angle->explanation = $explanation;
            if ($angle->isDirty('explanation')) {
                $angle->save();
                Audit::log(
                    action: AuditAction::WavePersonaAngleUpdated,
                    actor: $actor,
                    subject: $angle,
                    metadata: ['wave' => $locked->ulid, 'persona' => $persona->ulid, 'angle' => $angle->ulid, 'changed' => ['explanation']],
                    agencyId: $locked->agency_id,
                );
            }

            return $angle->load(['usp', 'persona']);
        });
    }

    public function remove(Brand $brand, Wave $wave, string $personaUlid, string $angleUlid, User $actor): void
    {
        StrategyGuard::assertBrandWritable($brand);

        DB::transaction(function () use ($wave, $personaUlid, $angleUlid, $actor): void {
            $locked = StrategyGuard::lockWave($wave);
            $persona = $this->persona($locked, $personaUlid);
            $angle = $this->angle($persona, $angleUlid);
            if ($angle->origin === AngleOrigin::Matrix) {
                throw StrategyRuleViolation::angleSyncedReadOnly();
            }
            $usp = WaveUsp::query()->whereKey($angle->usp_id)->firstOrFail();
            $uspUlid = $usp->ulid;
            $angle->delete();
            // A hand-written angle that was holding the place of a Priority/Support cell's angle is gone: the cell's
            // synced angle takes over in this same transaction (plan 1.7 row 15).
            $matrixAngleCreated = MatrixService::reconcileAfterManualRemoval($locked, $persona, $usp);

            Audit::log(
                action: AuditAction::WavePersonaAngleRemoved,
                actor: $actor,
                subject: $angle,
                metadata: ['wave' => $locked->ulid, 'persona' => $persona->ulid, 'angle' => $angle->ulid, 'usp' => $uspUlid, 'matrix_angle_created' => $matrixAngleCreated],
                agencyId: $locked->agency_id,
            );
        });
    }

    /**
     * PL-2.2 ruling change R2: "Use the matrix line". A hand-written angle and the Priority or Support cell of the same
     * persona and USP sit with two different texts; the user chose the cell's. The angle becomes a SYNCED one whose text
     * is the message line (same row, `origin = matrix`), in one transaction under the wave lock. Idempotent: an angle that
     * is already synced is returned as it is, nothing written, nothing audited. Refused when there is no Priority or
     * Support cell with a message line (422 `angle.no_matrix_line`) or when the two texts are already equal (422
     * `angle.nothing_to_resolve`). Audit: ids and counts, never a text.
     */
    public function useMatrixLine(Brand $brand, Wave $wave, string $personaUlid, string $angleUlid, User $actor): WavePersonaAngle
    {
        StrategyGuard::assertBrandWritable($brand);

        return DB::transaction(function () use ($wave, $personaUlid, $angleUlid, $actor): WavePersonaAngle {
            $locked = StrategyGuard::lockWave($wave);
            $persona = $this->persona($locked, $personaUlid);
            $angle = $this->angle($persona, $angleUlid);
            if ($angle->origin === AngleOrigin::Matrix) {
                return $angle->load(['usp', 'persona']);
            }

            $cell = WaveMatrixCell::query()->where('persona_id', $persona->id)->where('usp_id', $angle->usp_id)->first();
            if ($cell === null || ! $cell->level->feedsAngle() || $cell->message_line === null || $cell->message_line === '') {
                throw StrategyRuleViolation::angleNoMatrixLine();
            }
            if ($cell->message_line === $angle->explanation) {
                throw StrategyRuleViolation::angleNothingToResolve();
            }

            $angle->explanation = $cell->message_line;
            $angle->origin = AngleOrigin::Matrix;
            $angle->save();

            Audit::log(
                action: AuditAction::WavePersonaAngleMatrixLineUsed,
                actor: $actor,
                subject: $angle,
                metadata: ['wave' => $locked->ulid, 'persona' => $persona->ulid, 'angle' => $angle->ulid, 'usp' => $angle->usp->ulid, 'market' => $persona->market, 'level' => $cell->level->value, 'angles_converted' => 1],
                agencyId: $locked->agency_id,
            );

            return $angle->load(['usp', 'persona']);
        });
    }

    private function persona(Wave $wave, string $ulid): WavePersona
    {
        return WavePersona::query()->where('ulid', $ulid)->where('wave_id', $wave->id)->where('agency_id', $wave->agency_id)->firstOrFail();
    }

    private function angle(WavePersona $persona, string $ulid): WavePersonaAngle
    {
        return WavePersonaAngle::query()->where('ulid', $ulid)->where('persona_id', $persona->id)->where('wave_id', $persona->wave_id)->firstOrFail();
    }
}
