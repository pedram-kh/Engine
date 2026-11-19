<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Enums\AngleOrigin;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WavePersonaAngle;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Waves\Models\Wave;
use Illuminate\Support\Facades\DB;

/**
 * A persona's angles: one line linking a persona to a USP of the SAME wave (PL-2.1). The persona and the USP are looked
 * up through the wave (404 otherwise) and the composite foreign keys make the same-wave rule true in the database. One
 * angle per persona per USP. Written by hand only (`origin = manual`). Audit rows carry ids, never the line.
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

            return $angle->load('usp');
        });
    }

    public function update(Brand $brand, Wave $wave, string $personaUlid, string $angleUlid, User $actor, string $explanation): WavePersonaAngle
    {
        StrategyGuard::assertBrandWritable($brand);

        return DB::transaction(function () use ($wave, $personaUlid, $angleUlid, $actor, $explanation): WavePersonaAngle {
            $locked = StrategyGuard::lockWave($wave);
            $persona = $this->persona($locked, $personaUlid);
            $angle = $this->angle($persona, $angleUlid);

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

            return $angle->load('usp');
        });
    }

    public function remove(Brand $brand, Wave $wave, string $personaUlid, string $angleUlid, User $actor): void
    {
        StrategyGuard::assertBrandWritable($brand);

        DB::transaction(function () use ($wave, $personaUlid, $angleUlid, $actor): void {
            $locked = StrategyGuard::lockWave($wave);
            $persona = $this->persona($locked, $personaUlid);
            $angle = $this->angle($persona, $angleUlid);
            $uspUlid = WaveUsp::query()->whereKey($angle->usp_id)->value('ulid');
            $angle->delete();

            Audit::log(
                action: AuditAction::WavePersonaAngleRemoved,
                actor: $actor,
                subject: $angle,
                metadata: ['wave' => $locked->ulid, 'persona' => $persona->ulid, 'angle' => $angle->ulid, 'usp' => $uspUlid],
                agencyId: $locked->agency_id,
            );
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
