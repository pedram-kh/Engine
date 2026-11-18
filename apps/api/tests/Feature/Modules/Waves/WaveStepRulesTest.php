<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Enums\BrandStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveStep;
use App\Modules\Waves\Steps\WaveStepKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TrackerFixtures as T;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-1.3 — the rules of the step and stream endpoints: O1, O2, OC1, SS1, ST1, ST2, SRC1, S1, A1, P1 (first open).
 */

/**
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function trkPatch(array $w, string $key, array $body, string $as = 'admin'): TestResponse
{
    $step = T::step($w['wave'], $key);

    return T::call($w[$as], 'PATCH', T::url($w['agency'], $w['brand'], $w['wave'], 'steps/'.$step->ulid), $body);
}

it('P1: the first open of a wave created before this build provisions the 24 steps, once, with no duplicate on reload', function (): void {
    $w = T::world(provisioned: false);
    $url = T::url($w['agency'], $w['brand'], $w['wave'], 'tracker');
    expect(WaveStep::query()->count())->toBe(0);

    $first = T::call($w['admin'], 'GET', $url)->assertOk();
    T::call($w['admin'], 'GET', $url)->assertOk();
    T::call($w['staff'], 'GET', $url)->assertOk();

    expect(WaveStep::query()->where('wave_id', $w['wave']->id)->count())->toBe(24)
        ->and($first->json('data.attributes.progress.label_kind'))->toBe('not_started')
        ->and(collect(T::rows($first, 'data.attributes.steps'))->where('attributes.owner_client', true)->pluck('attributes.key')->all())
        ->toBe(['ugc.client_approval', 'statics.client_feedback', 'stills.client_approval'])
        ->and(AuditLog::query()->where('action', AuditAction::WaveStepsProvisioned->value)->count())->toBe(1);
});

it('O1: an owner must be an active member of the same agency; anything else is 422 and nothing changes; null clears', function (): void {
    $w = T::world();
    $pending = User::factory()->createOne();
    AgencyMembership::factory()->pendingInvitation()->forAgency($w['agency'])->create(['user_id' => $pending->id]);
    $removed = User::factory()->agencyStaff($w['agency'])->createOne();
    T::removeMembership($removed, $w['agency']);

    foreach ([
        $w['otherAdmin']->ulid, // a member of another agency
        $pending->ulid,
        $removed->ulid,
        '01ARZ3NDEKTSV4RRFFQ69G5FAV', // does not exist
    ] as $owner) {
        trkPatch($w, WaveStepKey::STATICS_DESIGN_V1, ['owner' => $owner])->assertStatus(422)->assertJsonPath('errors.0.code', 'step.owner_not_a_member');
    }
    expect(T::step($w['wave'], WaveStepKey::STATICS_DESIGN_V1)->owner_user_id)->toBeNull();

    trkPatch($w, WaveStepKey::STATICS_DESIGN_V1, ['owner' => $w['member']->ulid])->assertOk()->assertJsonPath('data.attributes.owner.name', $w['member']->name);
    trkPatch($w, WaveStepKey::STATICS_DESIGN_V1, ['owner' => null])->assertOk()->assertJsonPath('data.attributes.owner', null);
    expect(T::step($w['wave'], WaveStepKey::STATICS_DESIGN_V1)->owner_user_id)->toBeNull();

    // Add accepts the same rule.
    T::call($w['admin'], 'POST', T::url($w['agency'], $w['brand'], $w['wave'], 'steps'), ['stream' => 'statics', 'name' => 'X', 'owner' => $w['otherAdmin']->ulid])
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'step.owner_not_a_member');
});

it('O2: a removed member shows as owner null with owner_is_former, in the tracker and after a PATCH, and writes nothing on removal', function (): void {
    $w = T::world();
    trkPatch($w, WaveStepKey::STATICS_DESIGN_V1, ['owner' => $w['member']->ulid])->assertOk();
    T::removeMembership($w['member'], $w['agency']);

    $res = T::call($w['admin'], 'GET', T::url($w['agency'], $w['brand'], $w['wave'], 'tracker'))->assertOk();
    $step = collect(T::rows($res, 'data.attributes.steps'))->firstWhere('attributes.key', WaveStepKey::STATICS_DESIGN_V1);

    expect(data_get($step, 'attributes.owner'))->toBeNull()
        ->and(data_get($step, 'attributes.owner_is_former'))->toBeTrue()
        ->and(T::step($w['wave'], WaveStepKey::STATICS_DESIGN_V1)->owner_user_id)->toBe($w['member']->id)
        ->and(data_get(collect(T::rows($res, 'data.attributes.streams'))->firstWhere('stream', 'statics'), 'owners'))->toBe([]);
});

it('OC1: "Client" is the only non-member owner; it clears the member and a member clears it; both at once is 422', function (): void {
    $w = T::world();

    trkPatch($w, WaveStepKey::STATICS_DESIGN_V1, ['owner' => $w['member']->ulid])->assertOk();
    trkPatch($w, WaveStepKey::STATICS_DESIGN_V1, ['owner_client' => true])->assertOk()
        ->assertJsonPath('data.attributes.owner_client', true)->assertJsonPath('data.attributes.owner', null);
    expect(T::step($w['wave'], WaveStepKey::STATICS_DESIGN_V1)->owner_user_id)->toBeNull();

    trkPatch($w, WaveStepKey::STATICS_DESIGN_V1, ['owner' => $w['member']->ulid])->assertOk()
        ->assertJsonPath('data.attributes.owner_client', false)->assertJsonPath('data.attributes.owner.id', $w['member']->ulid);

    trkPatch($w, WaveStepKey::STATICS_DESIGN_V1, ['owner' => $w['member']->ulid, 'owner_client' => true])
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'step.owner_conflict');

    // Any other string is not accepted as an owner: the word itself, a name, an e-mail.
    foreach (['Client', 'client', 'Lena K.', 'someone@example.com'] as $word) {
        trkPatch($w, WaveStepKey::STATICS_DESIGN_V1, ['owner' => $word])->assertStatus(422);
    }

    // "Unassigned" from the picker: both cleared.
    trkPatch($w, WaveStepKey::STATICS_DESIGN_V1, ['owner' => null, 'owner_client' => false])->assertOk()
        ->assertJsonPath('data.attributes.owner', null)->assertJsonPath('data.attributes.owner_client', false);

    // The three default client steps are Client.
    $res = T::call($w['admin'], 'GET', T::url($w['agency'], $w['brand'], $w['wave'], 'tracker'));
    expect(collect(T::rows($res, 'data.attributes.steps'))->where('attributes.owner_client', true)->pluck('attributes.key')->all())
        ->toBe(['ugc.client_approval', 'statics.client_feedback', 'stills.client_approval']);

    // And a step can be added with the Client as owner.
    T::call($w['admin'], 'POST', T::url($w['agency'], $w['brand'], $w['wave'], 'steps'), ['stream' => 'stills', 'name' => 'Sign-off', 'owner_client' => true])
        ->assertCreated()->assertJsonPath('data.attributes.owner_client', true);
});

it('SS1: a strategy step cannot be removed or moved by anyone; a rename works; every other step can be removed', function (): void {
    $w = T::world();

    foreach (WaveStepKey::STRATEGY as $key) {
        $step = T::step($w['wave'], $key);
        foreach (['admin', 'manager'] as $role) {
            T::call($w[$role], 'DELETE', T::url($w['agency'], $w['brand'], $w['wave'], 'steps/'.$step->ulid))
                ->assertStatus(422)->assertJsonPath('errors.0.code', 'step.strategy_locked');
            trkPatch($w, $key, ['stream' => 'stills'], $role)->assertStatus(422)->assertJsonPath('errors.0.code', 'step.strategy_locked');
        }
        // Staff is refused earlier (403) and the row is still there.
        T::call($w['staff'], 'DELETE', T::url($w['agency'], $w['brand'], $w['wave'], 'steps/'.$step->ulid))->assertForbidden();
        trkPatch($w, $key, ['stream' => 'stills'], 'staff')->assertForbidden();

        expect(T::step($w['wave'], $key)->stream->value)->toBe('ugc_video');
        trkPatch($w, $key, ['name' => 'Renamed '.$key], 'manager')->assertOk();
        expect(T::step($w['wave'], $key)->name)->toBe('Renamed '.$key);
    }

    // The same stream value is not a move.
    trkPatch($w, WaveStepKey::UGC_USPS, ['stream' => 'ugc_video', 'name' => 'Value props'])->assertOk();

    $others = WaveStep::query()->where('wave_id', $w['wave']->id)->whereNotIn('step_key', WaveStepKey::STRATEGY)->get();
    expect($others)->toHaveCount(18);
    foreach ($others as $step) {
        T::call($w['admin'], 'DELETE', T::url($w['agency'], $w['brand'], $w['wave'], 'steps/'.$step->ulid))->assertNoContent();
    }
    expect(WaveStep::query()->where('wave_id', $w['wave']->id)->count())->toBe(6);
});

it('SS1: a non-strategy step can move to another stream, appended at the end', function (): void {
    $w = T::world();

    trkPatch($w, WaveStepKey::STATICS_DESIGN_V1, ['stream' => 'stills'])->assertOk()
        ->assertJsonPath('data.attributes.stream', 'stills')->assertJsonPath('data.attributes.position', 70);
});

it('ST1: the UGC stream cannot be removed; Statics and Stills can, in one action with one audit row', function (): void {
    $w = T::world();
    $base = T::url($w['agency'], $w['brand'], $w['wave'], 'streams/');

    T::call($w['admin'], 'DELETE', $base.'ugc_video')->assertStatus(422)->assertJsonPath('errors.0.code', 'stream.not_removable');
    expect(WaveStep::query()->where('wave_id', $w['wave']->id)->where('stream', 'ugc_video')->count())->toBe(13);

    $res = T::call($w['admin'], 'DELETE', $base.'statics')->assertOk();
    expect(array_column($res->json('data.attributes.streams'), 'stream'))->toBe(['ugc_video', 'stills'])
        ->and($res->json('data.attributes.absent_streams'))->toBe(['statics'])
        ->and(AuditLog::query()->where('action', AuditAction::WaveStreamRemoved->value)->count())->toBe(1)
        ->and(WaveStep::query()->where('wave_id', $w['wave']->id)->where('stream', 'statics')->count())->toBe(0);

    T::call($w['manager'], 'DELETE', $base.'stills')->assertOk();
    expect(AuditLog::query()->where('action', AuditAction::WaveStreamRemoved->value)->count())->toBe(2)
        ->and(WaveStep::query()->where('wave_id', $w['wave']->id)->count())->toBe(13);

    // A stream that is already gone is a 404.
    T::call($w['admin'], 'DELETE', $base.'statics')->assertNotFound();
});

it('ST1: removing the last step of a stream one by one also removes the stream', function (): void {
    $w = T::world();
    foreach (WaveStep::query()->where('wave_id', $w['wave']->id)->where('stream', 'statics')->get() as $step) {
        T::call($w['admin'], 'DELETE', T::url($w['agency'], $w['brand'], $w['wave'], 'steps/'.$step->ulid))->assertNoContent();
    }

    $res = T::call($w['admin'], 'GET', T::url($w['agency'], $w['brand'], $w['wave'], 'tracker'))->assertOk();
    expect(array_column($res->json('data.attributes.streams'), 'stream'))->toBe(['ugc_video', 'stills'])
        ->and($res->json('data.attributes.absent_streams'))->toBe(['statics']);
});

it('ST2: a removed stream stays removed on later opens and returns only by Add stream, once', function (): void {
    $w = T::world();
    $tracker = T::url($w['agency'], $w['brand'], $w['wave'], 'tracker');
    $statics = T::url($w['agency'], $w['brand'], $w['wave'], 'streams/statics');
    $marker = Wave::query()->findOrFail($w['wave']->id)->steps_provisioned_at;

    T::call($w['admin'], 'DELETE', $statics)->assertOk();
    T::call($w['admin'], 'GET', $tracker)->assertOk();
    T::call($w['staff'], 'GET', $tracker)->assertOk();
    expect(WaveStep::query()->where('wave_id', $w['wave']->id)->where('stream', 'statics')->count())->toBe(0)
        ->and(Wave::query()->findOrFail($w['wave']->id)->steps_provisioned_at?->toIso8601String())->toBe($marker?->toIso8601String());

    $res = T::call($w['admin'], 'POST', $statics)->assertOk();
    $keys = WaveStep::query()->where('wave_id', $w['wave']->id)->where('stream', 'statics')->orderBy('position')->pluck('step_key')->all();
    expect($keys)->toBe(['statics.brief_references', 'statics.design_v1', 'statics.internal_review', 'statics.client_feedback', 'statics.final_export'])
        ->and(count($res->json('data.attributes.steps')))->toBe(24)
        ->and(AuditLog::query()->where('action', AuditAction::WaveStreamAdded->value)->count())->toBe(1)
        ->and(Wave::query()->findOrFail($w['wave']->id)->steps_provisioned_at?->toIso8601String())->toBe($marker?->toIso8601String());

    // A second Add stream writes nothing.
    T::call($w['admin'], 'POST', $statics)->assertOk();
    expect(WaveStep::query()->where('wave_id', $w['wave']->id)->where('stream', 'statics')->count())->toBe(5)
        ->and(AuditLog::query()->where('action', AuditAction::WaveStreamAdded->value)->count())->toBe(1);

    // The restored client step is "Client" again.
    expect(T::step($w['wave'], WaveStepKey::STATICS_CLIENT_FEEDBACK)->owner_client)->toBeTrue();
});

it('ST2: Add stream does not touch a stream that has steps, custom steps removed with the stream stay removed', function (): void {
    $w = T::world();
    T::call($w['admin'], 'POST', T::url($w['agency'], $w['brand'], $w['wave'], 'steps'), ['stream' => 'statics', 'name' => 'Custom'])->assertCreated();
    T::call($w['admin'], 'DELETE', T::url($w['agency'], $w['brand'], $w['wave'], 'streams/statics'))->assertOk();
    T::call($w['admin'], 'POST', T::url($w['agency'], $w['brand'], $w['wave'], 'streams/statics'))->assertOk();

    expect(WaveStep::query()->where('wave_id', $w['wave']->id)->where('stream', 'statics')->where('name', 'Custom')->count())->toBe(0)
        ->and(WaveStep::query()->where('wave_id', $w['wave']->id)->where('stream', 'statics')->count())->toBe(5);
});

it('SRC1: a status change through the API sets status_source manual; provisioning sets system; an unchanged status keeps it', function (): void {
    $w = T::world();
    expect(WaveStep::query()->where('wave_id', $w['wave']->id)->where('status_source', 'system')->count())->toBe(24);

    trkPatch($w, WaveStepKey::UGC_USPS, ['due_on' => '2026-12-01'])->assertOk()->assertJsonPath('data.attributes.status_source', 'system');
    trkPatch($w, WaveStepKey::UGC_USPS, ['status' => 'not_started'])->assertOk()->assertJsonPath('data.attributes.status_source', 'system');
    trkPatch($w, WaveStepKey::UGC_USPS, ['status' => 'in_progress'])->assertOk()->assertJsonPath('data.attributes.status_source', 'manual');
    expect(T::step($w['wave'], WaveStepKey::UGC_USPS)->status_source->value)->toBe('manual')
        ->and(WaveStep::query()->where('wave_id', $w['wave']->id)->where('status_source', 'manual')->count())->toBe(1);
});

it('completed_on is the actor\'s today when a step becomes done, and cleared when it leaves done', function (): void {
    $w = T::world();
    $w['admin']->forceFill(['timezone' => 'Pacific/Auckland'])->save();
    Carbon::setTestNow('2026-11-18 23:30:00 UTC');

    trkPatch($w, WaveStepKey::UGC_USPS, ['status' => 'done'])->assertOk()->assertJsonPath('data.attributes.completed_on', '2026-11-19');
    trkPatch($w, WaveStepKey::UGC_USPS, ['status' => 'in_progress'])->assertOk()->assertJsonPath('data.attributes.completed_on', null);

    Carbon::setTestNow();
});

it('the dates rule: a due date before the start date is 422 step.dates_invalid, on add and on update', function (): void {
    $w = T::world();

    trkPatch($w, WaveStepKey::UGC_USPS, ['starts_on' => '2026-12-10', 'due_on' => '2026-12-01'])->assertStatus(422)->assertJsonPath('errors.0.code', 'step.dates_invalid');
    trkPatch($w, WaveStepKey::UGC_USPS, ['starts_on' => '2026-12-10'])->assertOk();
    trkPatch($w, WaveStepKey::UGC_USPS, ['due_on' => '2026-12-01'])->assertStatus(422)->assertJsonPath('errors.0.code', 'step.dates_invalid');
    T::call($w['admin'], 'POST', T::url($w['agency'], $w['brand'], $w['wave'], 'steps'), ['stream' => 'statics', 'name' => 'X', 'starts_on' => '2026-12-10', 'due_on' => '2026-12-01'])
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'step.dates_invalid');
    trkPatch($w, WaveStepKey::UGC_USPS, ['starts_on' => null, 'due_on' => null])->assertOk();
    // Validation: name length, stream, status, date format.
    trkPatch($w, WaveStepKey::UGC_USPS, ['name' => str_repeat('x', 161)])->assertStatus(422);
    trkPatch($w, WaveStepKey::UGC_USPS, ['status' => 'delayed'])->assertStatus(422);
    trkPatch($w, WaveStepKey::UGC_USPS, ['due_on' => '12/01/2026'])->assertStatus(422);
    T::call($w['admin'], 'POST', T::url($w['agency'], $w['brand'], $w['wave'], 'steps'), ['stream' => 'podcasts', 'name' => 'X'])->assertStatus(422);
});

it('an archived brand is read-only for steps (the wave rule)', function (): void {
    $w = T::world();
    $w['brand']->forceFill(['status' => BrandStatus::Archived])->save();

    trkPatch($w, WaveStepKey::UGC_USPS, ['status' => 'done'])->assertStatus(422)->assertJsonPath('errors.0.code', 'wave.brand_archived');
    T::call($w['admin'], 'GET', T::url($w['agency'], $w['brand'], $w['wave'], 'tracker'))->assertOk();
});

it('S1: a PATCH or DELETE after the step was removed is 404, and nothing is half applied', function (): void {
    $w = T::world();
    $step = T::step($w['wave'], WaveStepKey::STATICS_DESIGN_V1);
    $url = T::url($w['agency'], $w['brand'], $w['wave'], 'steps/'.$step->ulid);

    T::call($w['admin'], 'DELETE', $url)->assertNoContent();
    T::call($w['staff'], 'PATCH', $url, ['status' => 'done'])->assertNotFound();
    T::call($w['admin'], 'DELETE', $url)->assertNotFound();

    // A removed stream: a step edit of that stream is 404 too.
    $other = T::step($w['wave'], WaveStepKey::STILLS_SHOT_LIST);
    T::call($w['admin'], 'DELETE', T::url($w['agency'], $w['brand'], $w['wave'], 'streams/stills'))->assertOk();
    T::call($w['staff'], 'PATCH', T::url($w['agency'], $w['brand'], $w['wave'], 'steps/'.$other->ulid), ['status' => 'done'])->assertNotFound();
});

it('S1: two edits of different fields both persist, the same field is last write wins', function (): void {
    $w = T::world();

    trkPatch($w, WaveStepKey::STATICS_DESIGN_V1, ['owner' => $w['member']->ulid], 'staff')->assertOk();
    trkPatch($w, WaveStepKey::STATICS_DESIGN_V1, ['due_on' => '2026-12-05'], 'manager')->assertOk();
    $step = T::step($w['wave'], WaveStepKey::STATICS_DESIGN_V1);
    expect($step->owner_user_id)->toBe($w['member']->id)->and($step->due_on?->toDateString())->toBe('2026-12-05');

    trkPatch($w, WaveStepKey::STATICS_DESIGN_V1, ['due_on' => '2026-12-07'], 'staff')->assertOk();
    trkPatch($w, WaveStepKey::STATICS_DESIGN_V1, ['due_on' => '2026-12-09'], 'manager')->assertOk();
    expect(T::step($w['wave'], WaveStepKey::STATICS_DESIGN_V1)->due_on?->toDateString())->toBe('2026-12-09');
});

it('S1: two adds to one stream take different, increasing positions (the unique index is the belt)', function (): void {
    $w = T::world();
    $url = T::url($w['agency'], $w['brand'], $w['wave'], 'steps');

    $a = T::call($w['admin'], 'POST', $url, ['stream' => 'statics', 'name' => 'A'])->assertCreated()->json('data.attributes.position');
    $b = T::call($w['manager'], 'POST', $url, ['stream' => 'statics', 'name' => 'B'])->assertCreated()->json('data.attributes.position');
    $c = T::call($w['admin'], 'POST', $url, ['stream' => 'stills', 'name' => 'C'])->assertCreated()->json('data.attributes.position');

    expect([$a, $b, $c])->toBe([60, 70, 70]);
});

it('S1: the step edit, the add and the stream operations lock rows (the for update SQL) [PostgreSQL]', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('SQLite ignores row locks; the lock SQL is asserted on PostgreSQL.');
    }
    $w = T::world();
    $sql = [];
    DB::listen(function ($q) use (&$sql): void {
        $sql[] = $q->sql;
    });

    trkPatch($w, WaveStepKey::STATICS_DESIGN_V1, ['status' => 'done']);
    $lockSql = collect($sql)->first(fn (string $s): bool => str_contains($s, 'from "wave_steps"') && str_contains($s, 'for update'));
    expect($lockSql)->not->toBeNull();

    $sql = [];
    T::call($w['admin'], 'POST', T::url($w['agency'], $w['brand'], $w['wave'], 'steps'), ['stream' => 'statics', 'name' => 'A']);
    // Two: the provisioner's marker check and the add's own position lock.
    expect(collect($sql)->filter(fn (string $s): bool => str_contains($s, 'from "waves"') && str_contains($s, 'for update'))->count())->toBe(2);

    $sql = [];
    T::call($w['admin'], 'DELETE', T::url($w['agency'], $w['brand'], $w['wave'], 'streams/statics'));
    expect(collect($sql)->contains(fn (string $s): bool => str_contains($s, 'from "waves"') && str_contains($s, 'for update')))->toBeTrue();
});

it('A1: each action writes one audit row with decided keys and never a name or an e-mail of a person', function (): void {
    $w = T::world();
    $url = T::url($w['agency'], $w['brand'], $w['wave'], 'steps');

    $added = T::call($w['admin'], 'POST', $url, ['stream' => 'statics', 'name' => 'Resize', 'owner' => $w['member']->ulid, 'due_on' => '2026-12-01'])->assertCreated();
    $id = $added->json('data.id');
    trkPatch($w, WaveStepKey::STATICS_DESIGN_V1, ['status' => 'done', 'owner' => $w['member']->ulid]);
    T::call($w['admin'], 'DELETE', T::url($w['agency'], $w['brand'], $w['wave'], 'steps/'.$id))->assertNoContent();
    T::call($w['admin'], 'DELETE', T::url($w['agency'], $w['brand'], $w['wave'], 'streams/stills'))->assertOk();
    T::call($w['admin'], 'POST', T::url($w['agency'], $w['brand'], $w['wave'], 'streams/stills'))->assertOk();

    $one = fn (AuditAction $a): AuditLog => AuditLog::query()->where('action', $a->value)->firstOrFail();
    foreach ([AuditAction::WaveStepsProvisioned, AuditAction::WaveStepAdded, AuditAction::WaveStepUpdated, AuditAction::WaveStepRemoved, AuditAction::WaveStreamRemoved, AuditAction::WaveStreamAdded] as $action) {
        expect(AuditLog::query()->where('action', $action->value)->count())->toBe(1, $action->value);
    }

    $update = $one(AuditAction::WaveStepUpdated);
    expect(data_get($update->before, 'status'))->toBe('not_started')->and(data_get($update->after, 'status'))->toBe('done')
        ->and(data_get($update->after, 'status_source'))->toBe('manual')
        ->and($update->metadata)->toBe(['from_user' => null, 'to_user' => $w['member']->id])
        ->and($one(AuditAction::WaveStepAdded)->metadata)->toBe(['to_user' => $w['member']->id])
        ->and($one(AuditAction::WaveStreamRemoved)->metadata)->toBe(['stream' => 'stills', 'row_count' => 6])
        ->and($one(AuditAction::WaveStreamAdded)->metadata)->toBe(['stream' => 'stills', 'row_count' => 6]);

    $all = json_encode(AuditLog::query()->whereIn('action', [
        'wave_steps.provisioned', 'wave_step.added', 'wave_step.updated', 'wave_step.removed', 'wave_stream.removed', 'wave_stream.added',
    ])->get(['before', 'after', 'metadata'])->toArray(), JSON_THROW_ON_ERROR);
    expect($all)->not->toContain($w['member']->email)->and($all)->not->toContain((string) $w['member']->name);
});

it('A1: a request that changes nothing writes no audit row', function (): void {
    $w = T::world();

    trkPatch($w, WaveStepKey::STATICS_DESIGN_V1, ['status' => 'not_started', 'owner' => null])->assertOk();
    T::call($w['admin'], 'POST', T::url($w['agency'], $w['brand'], $w['wave'], 'streams/ugc_video'))->assertOk();

    expect(AuditLog::query()->where('action', AuditAction::WaveStepUpdated->value)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::WaveStreamAdded->value)->count())->toBe(0);
});
