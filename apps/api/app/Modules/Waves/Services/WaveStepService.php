<?php

declare(strict_types=1);

namespace App\Modules\Waves\Services;

use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Enums\BrandStatus;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Contracts\WaveStrategyState;
use App\Modules\Waves\Enums\WaveStepSource;
use App\Modules\Waves\Enums\WaveStepStatus;
use App\Modules\Waves\Enums\WaveStream;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveStep;
use App\Modules\Waves\Steps\WaveStepDefaults;
use App\Modules\Waves\Steps\WaveStrategyRowRules;
use App\Modules\Waves\Steps\WaveTrackerCalculator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tracker writes (PL-1.3). The rules live here, not in the controller:
 *
 *   - an archived brand is read-only (the same rule as a wave edit);
 *   - an owner is an active member of the wave's agency, OR the word "Client" (`owner_client`), never both;
 *   - a strategy step can be renamed but not removed and not moved; the UGC video stream cannot be removed;
 *   - the status of a step is set by hand here (`status_source = manual`); "delayed" is never stored;
 *   - concurrency (plan 3.4): a step edit locks the step row and writes only the fields that were sent, so two people
 *     changing different fields both keep their change and the same field is last-write-wins; adds, stream moves and
 *     stream remove/add take the WAVE row lock first, and the unique (wave_id, stream, position) index is the belt.
 *
 * One audit row per request, literal payload keys, a user only ever as an id (`from_user`, `to_user`).
 */
final class WaveStepService
{
    /** @var list<string> the tracker rows that have a real strategy step (PL-2.5) */
    private const REAL_KEYS = ['ugc.usps', 'ugc.personas', 'ugc.matrix', 'ugc.concepts'];

    public function __construct(private readonly WaveStepProvisioner $provisioner, private readonly WaveStrategyState $strategy) {}

    /**
     * @param  array<string, mixed>  $data  stream, name, owner (ulid|null), owner_client, starts_on, due_on
     */
    public function add(Brand $brand, Wave $wave, User $actor, array $data): WaveStep
    {
        $this->assertBrandWritable($brand);
        $this->provisioner->ensure($wave);
        $stream = WaveStream::from((string) $data['stream']);

        return $this->retryOnPositionClash(function () use ($wave, $actor, $data, $stream): WaveStep {
            return DB::transaction(function () use ($wave, $actor, $data, $stream): WaveStep {
                $locked = Wave::query()->whereKey($wave->id)->lockForUpdate()->firstOrFail();

                [$ownerId, $ownerClient] = $this->resolveOwner($locked, array_key_exists('owner', $data) ? $data['owner'] : null, (bool) ($data['owner_client'] ?? false));
                $this->assertDates($data['starts_on'] ?? null, $data['due_on'] ?? null);

                $step = WaveStep::query()->create([
                    'agency_id' => $locked->agency_id,
                    'wave_id' => $locked->id,
                    'stream' => $stream,
                    'step_key' => null,
                    'name' => trim((string) $data['name']),
                    'position' => $this->nextPosition($locked, $stream),
                    'status' => WaveStepStatus::NotStarted,
                    'owner_user_id' => $ownerId,
                    'owner_client' => $ownerClient,
                    'status_source' => WaveStepSource::System,
                    'starts_on' => $data['starts_on'] ?? null,
                    'due_on' => $data['due_on'] ?? null,
                ]);

                Audit::log(
                    action: AuditAction::WaveStepAdded,
                    actor: $actor,
                    subject: $step,
                    // Literal keys on purpose (AuditPayloadKeysGuardTest reads them statically).
                    after: [
                        'stream' => $step->stream->value,
                        'step_key' => $step->step_key,
                        'name' => $step->name,
                        'status' => $step->status->value,
                        'owner_client' => $step->owner_client,
                        'status_source' => $step->status_source->value,
                        'starts_on' => $step->starts_on?->toDateString(),
                        'due_on' => $step->due_on?->toDateString(),
                        'completed_on' => $step->completed_on?->toDateString(),
                    ],
                    metadata: ['to_user' => $ownerId],
                    agencyId: $locked->agency_id,
                );

                return $step;
            });
        });
    }

    /**
     * A partial body: only the keys present are applied. `$canManage` says whether the actor may also rename or move
     * (the controller already refused a staff request that carries those keys; the service checks the strategy lock).
     *
     * @param  array<string, mixed>  $data  status, owner, owner_client, starts_on, due_on, name, stream
     */
    public function update(Brand $brand, Wave $wave, string $stepUlid, User $actor, array $data): WaveStep
    {
        $this->assertBrandWritable($brand);

        return $this->retryOnPositionClash(function () use ($wave, $stepUlid, $actor, $data): WaveStep {
            return DB::transaction(function () use ($wave, $stepUlid, $actor, $data): WaveStep {
                // A move appends to another stream, so it takes the wave lock BEFORE the step lock (the order every
                // stream operation uses); a plain edit takes the step row lock only.
                // PL-2.5: a status change on a row that has a real strategy step also takes the wave lock FIRST (the order
                // every strategy write uses), so it cannot interleave with the choke point that writes the same row.
                $locked = (array_key_exists('stream', $data) || $this->needsRealState($wave, $stepUlid, $data))
                    ? Wave::query()->whereKey($wave->id)->lockForUpdate()->firstOrFail()
                    : $wave;

                $step = $this->lockStep($wave, $stepUlid);
                $before = $this->snapshot($step);
                $fromUser = $step->owner_user_id;

                if (array_key_exists('name', $data)) {
                    $step->name = trim((string) $data['name']);
                }

                if (array_key_exists('stream', $data)) {
                    $target = WaveStream::from((string) $data['stream']);
                    if ($target !== $step->stream) {
                        if ($step->isStrategy()) {
                            throw WaveRuleViolation::stepStrategyLocked();
                        }
                        $step->stream = $target;
                        $step->position = $this->nextPosition($locked, $target);
                    }
                }

                if (array_key_exists('status', $data)) {
                    $this->applyStatus($step, WaveStepStatus::from((string) $data['status']), $actor, $locked, ($data['confirm_incomplete'] ?? false) === true);
                }

                if (array_key_exists('owner', $data) || array_key_exists('owner_client', $data)) {
                    $this->applyOwner($step, $data);
                }

                foreach (['starts_on', 'due_on'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $step->{$field} = $data[$field];
                    }
                }
                $this->assertDates($step->starts_on?->toDateString(), $step->due_on?->toDateString());

                if (! $step->isDirty()) {
                    return $step;
                }

                $step->save();
                $after = $this->snapshot($step);

                Audit::log(
                    action: AuditAction::WaveStepUpdated,
                    actor: $actor,
                    subject: $step,
                    // Literal keys on purpose (AuditPayloadKeysGuardTest reads them statically).
                    before: [
                        'stream' => $before['stream'],
                        'step_key' => $before['step_key'],
                        'name' => $before['name'],
                        'status' => $before['status'],
                        'owner_client' => $before['owner_client'],
                        'status_source' => $before['status_source'],
                        'starts_on' => $before['starts_on'],
                        'due_on' => $before['due_on'],
                        'completed_on' => $before['completed_on'],
                    ],
                    after: [
                        'stream' => $after['stream'],
                        'step_key' => $after['step_key'],
                        'name' => $after['name'],
                        'status' => $after['status'],
                        'owner_client' => $after['owner_client'],
                        'status_source' => $after['status_source'],
                        'starts_on' => $after['starts_on'],
                        'due_on' => $after['due_on'],
                        'completed_on' => $after['completed_on'],
                    ],
                    metadata: ['from_user' => $fromUser, 'to_user' => $step->owner_user_id],
                    agencyId: $step->agency_id,
                );

                return $step;
            });
        });
    }

    public function remove(Brand $brand, Wave $wave, string $stepUlid, User $actor): void
    {
        $this->assertBrandWritable($brand);

        DB::transaction(function () use ($wave, $stepUlid, $actor): void {
            $step = $this->lockStep($wave, $stepUlid);

            if ($step->isStrategy()) {
                throw WaveRuleViolation::stepStrategyLocked();
            }

            $before = $this->snapshot($step);
            $fromUser = $step->owner_user_id;
            $step->delete();

            Audit::log(
                action: AuditAction::WaveStepRemoved,
                actor: $actor,
                subject: $step,
                // Literal keys on purpose (AuditPayloadKeysGuardTest reads them statically).
                before: [
                    'stream' => $before['stream'],
                    'step_key' => $before['step_key'],
                    'name' => $before['name'],
                    'status' => $before['status'],
                    'owner_client' => $before['owner_client'],
                    'status_source' => $before['status_source'],
                    'starts_on' => $before['starts_on'],
                    'due_on' => $before['due_on'],
                    'completed_on' => $before['completed_on'],
                ],
                metadata: ['from_user' => $fromUser],
                agencyId: $step->agency_id,
            );
        });
    }

    /**
     * Remove every step of a stream, in one transaction, with one audit row. UGC video is refused. A stream with no
     * steps is a 404 (the model-not-found path), so a repeated request is not silently "ok".
     *
     * @return int how many steps were removed
     */
    public function removeStream(Brand $brand, Wave $wave, WaveStream $stream, User $actor): int
    {
        $this->assertBrandWritable($brand);

        if (! $stream->isRemovable()) {
            throw WaveRuleViolation::streamNotRemovable();
        }

        return DB::transaction(function () use ($wave, $stream, $actor): int {
            $locked = Wave::query()->whereKey($wave->id)->lockForUpdate()->firstOrFail();

            $count = WaveStep::query()->where('wave_id', $locked->id)->where('stream', $stream->value)->count();
            if ($count === 0) {
                throw (new ModelNotFoundException)->setModel(WaveStep::class);
            }

            WaveStep::query()->where('wave_id', $locked->id)->where('stream', $stream->value)->delete();

            Audit::log(
                action: AuditAction::WaveStreamRemoved,
                actor: $actor,
                subject: $locked,
                metadata: ['stream' => $stream->value, 'row_count' => $count],
                agencyId: $locked->agency_id,
            );

            return $count;
        });
    }

    /**
     * Bring a removed stream back with its DEFAULT steps. A stream that already has steps: nothing is written. This
     * never reads or changes `waves.steps_provisioned_at`; the unique (wave_id, step_key) index is the belt.
     *
     * @return bool true when steps were written
     */
    public function addStream(Brand $brand, Wave $wave, WaveStream $stream, User $actor): bool
    {
        $this->assertBrandWritable($brand);
        $this->provisioner->ensure($wave);

        try {
            return DB::transaction(function () use ($wave, $stream, $actor): bool {
                $locked = Wave::query()->whereKey($wave->id)->lockForUpdate()->firstOrFail();

                if (WaveStep::query()->where('wave_id', $locked->id)->where('stream', $stream->value)->exists()) {
                    return false;
                }

                // A default step moved to another stream by hand keeps its key (unique per wave), so it is left where
                // it is and only the default keys that are not on the wave are inserted.
                $existing = WaveStep::query()->where('wave_id', $locked->id)->pluck('step_key')->all();

                $now = now();
                $rows = [];
                foreach (WaveStepDefaults::forStream($stream) as $d) {
                    if (in_array($d['key'], $existing, true)) {
                        continue;
                    }
                    $rows[] = [
                        'ulid' => (string) Str::ulid(),
                        'agency_id' => $locked->agency_id,
                        'wave_id' => $locked->id,
                        'stream' => $stream->value,
                        'step_key' => $d['key'],
                        'name' => $d['name'],
                        'position' => $d['position'],
                        'status' => WaveStepStatus::NotStarted->value,
                        'owner_user_id' => null,
                        'owner_client' => $d['owner_client'],
                        'status_source' => WaveStepSource::System->value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($rows === []) {
                    throw WaveRuleViolation::streamDefaultsExist();
                }

                WaveStep::query()->insert($rows);

                Audit::log(
                    action: AuditAction::WaveStreamAdded,
                    actor: $actor,
                    subject: $locked,
                    metadata: ['stream' => $stream->value, 'row_count' => count($rows)],
                    agencyId: $locked->agency_id,
                );

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            // A default key of this stream still exists on the wave (a step moved here by hand, or a concurrent add).
            return false;
        }
    }

    private function lockStep(Wave $wave, string $ulid): WaveStep
    {
        return WaveStep::query()
            ->where('ulid', $ulid)
            ->where('wave_id', $wave->id)
            ->where('agency_id', $wave->agency_id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * PL-2.5: does this request set the status of a row that has a real strategy step (strategy ON)? Read without a lock;
     * the answer only decides whether the wave lock is taken first.
     *
     * @param  array<string, mixed>  $data
     */
    private function needsRealState(Wave $wave, string $stepUlid, array $data): bool
    {
        if (! array_key_exists('status', $data) || ! $this->strategy->active()) {
            return false;
        }
        $key = WaveStep::query()->where('wave_id', $wave->id)->where('ulid', $stepUlid)->value('step_key');

        return is_string($key) && in_array($key, self::REAL_KEYS, true);
    }

    private function applyStatus(WaveStep $step, WaveStepStatus $new, User $actor, Wave $wave, bool $confirmed): void
    {
        if ($new === $step->status) {
            return;
        }

        // PL-2.5 (F1): with strategy ON a row that has a real step compares the hand-set status with the real one, under
        // the wave lock. Beyond it: refused 409 until confirmed (then "set by hand"); equal: the system owns the row;
        // below it: set by hand. Without strategy ON, or for a row with no real step, nothing changes (PL-1.3).
        $source = WaveStepSource::Manual;
        $key = $step->step_key;
        if ($key !== null && in_array($key, self::REAL_KEYS, true) && $this->strategy->active()) {
            $real = $this->strategy->real($wave)[$key] ?? null;
            if ($real !== null) {
                if (WaveStrategyRowRules::beyond($new->value, $real['state'])) {
                    if (! $confirmed) {
                        throw WaveRuleViolation::stepIncomplete([
                            'step' => $key,
                            'real_state' => $real['state'],
                            'markets_done' => $real['markets_done'],
                            'markets_total' => $real['markets_total'],
                        ]);
                    }
                } elseif ($new->value === $real['state']) {
                    $source = WaveStepSource::System;
                }
            }
        }

        $step->status = $new;
        $step->status_source = $source;
        // `completed_on` is the actor's today when the step becomes done, cleared when it leaves done.
        $step->completed_on = $new === WaveStepStatus::Done
            ? Carbon::parse(WaveTrackerCalculator::todayFor($actor->timezone))
            : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyOwner(WaveStep $step, array $data): void
    {
        $wave = Wave::query()->whereKey($step->wave_id)->firstOrFail();
        $hasOwner = array_key_exists('owner', $data);
        $hasClient = array_key_exists('owner_client', $data);

        $ownerUlid = $hasOwner ? $data['owner'] : null;
        $client = $hasClient ? (bool) $data['owner_client'] : null;

        if ($ownerUlid !== null && $client === true) {
            throw WaveRuleViolation::stepOwnerConflict();
        }

        if ($ownerUlid !== null) {
            [$id] = $this->resolveOwner($wave, $ownerUlid, false);
            $step->owner_user_id = $id;
            $step->owner_client = false;

            return;
        }

        if ($client === true) {
            $step->owner_user_id = null;
            $step->owner_client = true;

            return;
        }

        // owner: null clears the member; owner_client: false clears the word. Each only when it was sent.
        if ($hasOwner) {
            $step->owner_user_id = null;
        }
        if ($client === false) {
            $step->owner_client = false;
        }
    }

    /**
     * @return array{0: int|null, 1: bool} the member id (or null) and the Client flag
     */
    private function resolveOwner(Wave $wave, mixed $ownerUlid, bool $client): array
    {
        if ($ownerUlid !== null && $client) {
            throw WaveRuleViolation::stepOwnerConflict();
        }
        if ($client) {
            return [null, true];
        }
        if ($ownerUlid === null) {
            return [null, false];
        }

        $userId = User::query()->where('ulid', (string) $ownerUlid)->value('id');
        $isActiveMember = $userId !== null && AgencyMembership::query()
            ->where('agency_id', $wave->agency_id)
            ->where('user_id', $userId)
            ->whereNotNull('accepted_at')
            ->whereNull('deleted_at')
            ->exists();

        if (! $isActiveMember) {
            throw WaveRuleViolation::stepOwnerNotAMember();
        }

        return [(int) $userId, false];
    }

    private function assertDates(mixed $starts, mixed $due): void
    {
        if ($starts !== null && $due !== null && (string) $due < (string) $starts) {
            throw WaveRuleViolation::stepDatesInvalid();
        }
    }

    /** The wave row must be locked by the caller. */
    private function nextPosition(Wave $locked, WaveStream $stream): int
    {
        $max = WaveStep::query()->where('wave_id', $locked->id)->where('stream', $stream->value)->max('position');

        return $max === null ? 10 : ((int) $max) + 10;
    }

    /**
     * Two adds at once are serialised by the wave lock; the unique index is the belt. The loser retries ONCE.
     *
     * @template T
     *
     * @param  \Closure(): T  $attempt
     * @return T
     */
    private function retryOnPositionClash(\Closure $attempt): mixed
    {
        try {
            return $attempt();
        } catch (UniqueConstraintViolationException) {
            return $attempt();
        }
    }

    /**
     * @return array{stream: string, step_key: string|null, name: string, status: string, owner_client: bool, status_source: string, starts_on: string|null, due_on: string|null, completed_on: string|null}
     */
    private function snapshot(WaveStep $step): array
    {
        return [
            'stream' => $step->stream->value,
            'step_key' => $step->step_key,
            'name' => $step->name,
            'status' => $step->status->value,
            'owner_client' => $step->owner_client,
            'status_source' => $step->status_source->value,
            'starts_on' => $step->starts_on?->toDateString(),
            'due_on' => $step->due_on?->toDateString(),
            'completed_on' => $step->completed_on?->toDateString(),
        ];
    }

    private function assertBrandWritable(Brand $brand): void
    {
        if ($brand->trashed() || $brand->status === BrandStatus::Archived) {
            throw WaveRuleViolation::brandArchived();
        }
    }
}
