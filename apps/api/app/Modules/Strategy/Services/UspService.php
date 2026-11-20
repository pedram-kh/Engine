<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Enums\UspColor;
use App\Modules\Strategy\Models\WaveMatrixCell;
use App\Modules\Strategy\Models\WavePersonaAngle;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Models\Wave;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * USP writes (PL-2.1, plan sections 1.1, 1.4, 1.10, 3.4). The rules live here, not in the controller:
 *
 *   - an archived brand is read-only;
 *   - every write takes the WAVE row lock first; the cap of six, the colour (the first free key of the wave, fixed for
 *     the life of the USP) and the position are decided under it; `unique (wave_id, color_key)` is the belt;
 *   - a PATCH writes only the fields that were sent (per-field last write wins, ruling Q4);
 *   - reorder takes the full ordered ulid list; a list that is not exactly the wave's USPs is 422;
 *   - deleting a USP that angles or matrix cells use needs the user to confirm the number of items (`confirm_count`); admin and manager only (S6).
 *
 * One audit row per request, literal payload keys, ids, counts, colour keys and field NAMES; never the text.
 */
final class UspService
{
    private const FIELDS = ['title', 'explainer', 'creative_angle', 'copy_seed'];

    /**
     * @return Collection<int, WaveUsp>
     */
    public function list(Wave $wave): Collection
    {
        return WaveUsp::query()->where('wave_id', $wave->id)->orderBy('position')->orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $data  title, explainer, creative_angle, copy_seed
     */
    public function create(Brand $brand, Wave $wave, User $actor, array $data): WaveUsp
    {
        StrategyGuard::assertBrandWritable($brand);

        return StrategyGuard::retryOnClash(fn (): WaveUsp => DB::transaction(function () use ($wave, $actor, $data): WaveUsp {
            $locked = StrategyGuard::lockWave($wave);

            $existing = WaveUsp::query()->where('wave_id', $locked->id)->get(['id', 'color_key', 'position']);
            if ($existing->count() >= StrategyLimits::USP_MAX) {
                throw StrategyRuleViolation::uspMaxReached();
            }

            $used = $existing->map(static fn (WaveUsp $u): string => $u->color_key->value)->all();
            $color = null;
            foreach (UspColor::cases() as $candidate) {
                if (! in_array($candidate->value, $used, true)) {
                    $color = $candidate;
                    break;
                }
            }
            if ($color === null) {
                throw StrategyRuleViolation::uspMaxReached();
            }

            $usp = WaveUsp::query()->create([
                'agency_id' => $locked->agency_id,
                'wave_id' => $locked->id,
                'color_key' => $color,
                'position' => (int) $existing->max('position') + 1,
                'title' => (string) $data['title'],
                'explainer' => $data['explainer'] ?? null,
                'creative_angle' => $data['creative_angle'] ?? null,
                'copy_seed' => $data['copy_seed'] ?? null,
            ]);

            Audit::log(
                action: AuditAction::WaveUspCreated,
                actor: $actor,
                subject: $usp,
                // Literal keys on purpose (AuditPayloadKeysGuardTest reads them statically). Never the text.
                metadata: ['wave' => $locked->ulid, 'usp' => $usp->ulid, 'color_key' => $color->value],
                agencyId: $locked->agency_id,
            );

            return $usp;
        }));
    }

    /**
     * A partial body: only the keys present are applied.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Brand $brand, Wave $wave, string $uspUlid, User $actor, array $data): WaveUsp
    {
        StrategyGuard::assertBrandWritable($brand);

        return DB::transaction(function () use ($wave, $uspUlid, $actor, $data): WaveUsp {
            $locked = StrategyGuard::lockWave($wave);
            $usp = $this->find($locked, $uspUlid);

            foreach (self::FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $usp->{$field} = $data[$field];
                }
            }

            $changed = array_values(array_filter(self::FIELDS, static fn (string $f): bool => $usp->isDirty($f)));
            if ($changed === []) {
                return $usp;
            }
            $usp->save();

            Audit::log(
                action: AuditAction::WaveUspUpdated,
                actor: $actor,
                subject: $usp,
                metadata: ['wave' => $locked->ulid, 'usp' => $usp->ulid, 'changed' => $changed],
                agencyId: $locked->agency_id,
            );

            return $usp;
        });
    }

    /**
     * @param  list<string>  $order  every USP ulid of the wave, in the new order
     * @return Collection<int, WaveUsp>
     */
    public function reorder(Brand $brand, Wave $wave, User $actor, array $order): Collection
    {
        StrategyGuard::assertBrandWritable($brand);

        return DB::transaction(function () use ($wave, $actor, $order): Collection {
            $locked = StrategyGuard::lockWave($wave);

            /** @var Collection<string, WaveUsp> $byUlid */
            $byUlid = WaveUsp::query()->where('wave_id', $locked->id)->orderBy('position')->orderBy('id')->get()->keyBy('ulid');

            $current = $byUlid->keys()->all();
            $posted = array_values($order);
            $sameSet = count($posted) === count($current)
                && count(array_unique($posted)) === count($posted)
                && array_diff($posted, $current) === [];
            if (! $sameSet) {
                throw StrategyRuleViolation::uspOrderMismatch();
            }

            if ($posted === $current) {
                return $byUlid->values();
            }

            foreach ($posted as $index => $ulid) {
                /** @var WaveUsp $usp */
                $usp = $byUlid[$ulid];
                if ($usp->position !== $index + 1) {
                    $usp->position = $index + 1;
                    $usp->save();
                }
            }

            Audit::log(
                action: AuditAction::WaveUspReordered,
                actor: $actor,
                subject: $locked,
                metadata: ['wave' => $locked->ulid, 'order' => $posted],
                agencyId: $locked->agency_id,
            );

            return WaveUsp::query()->where('wave_id', $locked->id)->orderBy('position')->get();
        });
    }

    /**
     * @param  bool  $mayDeleteInUse  admin and manager (S6); decided by the controller from the policy
     * @param  int|null  $confirmCount  the number of items (angles and matrix cells) the user was shown and confirmed
     */
    public function delete(Brand $brand, Wave $wave, string $uspUlid, User $actor, bool $mayDeleteInUse, ?int $confirmCount): void
    {
        StrategyGuard::assertBrandWritable($brand);

        DB::transaction(function () use ($wave, $uspUlid, $actor, $mayDeleteInUse, $confirmCount): void {
            $locked = StrategyGuard::lockWave($wave);
            $usp = $this->find($locked, $uspUlid);

            // The "in use" test is made HERE, under the wave lock, so a USP that became in-use after the page rendered is
            // judged correctly: staff get 403, an admin or manager a fresh list.
            $affected = UspUsage::affected($usp);
            $items = count($affected);
            $angles = 0;
            $cells = 0;

            if ($items > 0) {
                if (! $mayDeleteInUse) {
                    throw StrategyRuleViolation::uspInUseForbidden();
                }
                if ($confirmCount === null || $confirmCount !== $items) {
                    throw StrategyRuleViolation::uspInUse($affected);
                }
                // Angles (hand-written and synced) first, then the cells: every foreign key is RESTRICT.
                $angles = WavePersonaAngle::query()->where('usp_id', $usp->id)->delete();
                $cells = WaveMatrixCell::query()->where('usp_id', $usp->id)->delete();
            }

            $color = $usp->color_key->value;
            $usp->delete();

            // Renumber 1..n (colours of the others do not change).
            $position = 0;
            foreach (WaveUsp::query()->where('wave_id', $locked->id)->orderBy('position')->orderBy('id')->get() as $rest) {
                $position++;
                if ($rest->position !== $position) {
                    $rest->position = $position;
                    $rest->save();
                }
            }

            Audit::log(
                action: AuditAction::WaveUspDeleted,
                actor: $actor,
                subject: $usp,
                metadata: ['wave' => $locked->ulid, 'usp' => $usp->ulid, 'color_key' => $color, 'angles_removed' => $angles, 'cells_removed' => $cells],
                agencyId: $locked->agency_id,
            );
        });
    }

    public function setAudienceTitle(Brand $brand, Wave $wave, User $actor, ?string $title): Wave
    {
        StrategyGuard::assertBrandWritable($brand);

        return DB::transaction(function () use ($wave, $actor, $title): Wave {
            $locked = StrategyGuard::lockWave($wave);
            $locked->usp_audience_title = $title;
            if (! $locked->isDirty('usp_audience_title')) {
                return $locked;
            }
            $locked->save();

            Audit::log(
                action: AuditAction::WaveAudienceTitleUpdated,
                actor: $actor,
                subject: $locked,
                metadata: ['wave' => $locked->ulid, 'changed' => ['usp_audience_title']],
                agencyId: $locked->agency_id,
            );

            return $locked;
        });
    }

    private function find(Wave $wave, string $ulid): WaveUsp
    {
        return WaveUsp::query()
            ->where('ulid', $ulid)
            ->where('wave_id', $wave->id)
            ->where('agency_id', $wave->agency_id)
            ->firstOrFail();
    }
}
