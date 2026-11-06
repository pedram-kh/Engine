<?php

declare(strict_types=1);

use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\AssignmentOfferEditor;
use App\Modules\Creators\Features\PerCampaignContractEnabled;
use App\Modules\Creators\Models\Contract;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;
use Tests\PostgresCommittedTestCase;

uses(PostgresCommittedTestCase::class)->group('pg');

require_once dirname(__DIR__, 3).'/Support/OfferEditHelpers.php';

/*
 * AH-119 A0 (closes docs/tech-debt.md "no two-connection proof"). AH-118 put `lockForUpdate()` on
 * the assignment row in two places: the offer edit (AssignmentOfferEditor) and the contract attach
 * (CampaignAssignmentContractController). SQLite ignores the lock; on Postgres it is the only
 * thing that serialises an edit against an attach. These specs use TWO real connections
 * (committed data, no wrapper transaction):
 *
 *   PROBE   while the app holds its lock (seen the instant its locking SELECT returns), a second
 *           connection's `FOR UPDATE NOWAIT` on the same row must be REFUSED (SQLSTATE 55P03).
 *           Remove `lockForUpdate()` and the probe gets the row: the spec fails.
 *   HOLDER  with the second connection holding the row, the app's write must wait, and with a
 *           300 ms lock_timeout it fails with 55P03 instead of writing around the holder.
 */

function secondConnection(): Connection
{
    config(['database.connections.pgsql_second' => config('database.connections.'.config('database.default'))]);

    return DB::connection('pgsql_second');
}

/** True when `FOR UPDATE NOWAIT` on the row is REFUSED by a lock somebody else holds. */
function rowIsLockedByAnother(Connection $probe, int $assignmentId): bool
{
    $probe->beginTransaction();
    try {
        $probe->select('select id from campaign_assignments where id = ? for update nowait', [$assignmentId]);

        return false;
    } catch (QueryException $e) {
        return $e->getCode() === '55P03';
    } finally {
        $probe->rollBack();
    }
}

/**
 * Runs $act and returns, per locking read on campaign_assignments the app issued, whether a
 * second connection was refused at that moment.
 *
 * @return list<bool>
 */
function probeWhileAppLocks(int $assignmentId, Closure $act): array
{
    $probe = secondConnection();
    $seen = [];

    DB::listen(function ($query) use ($probe, $assignmentId, &$seen): void {
        if ($query->connection === $probe) {
            return;
        }
        if (str_contains($query->sql, 'campaign_assignments') && str_contains(strtolower($query->sql), 'for update')) {
            $seen[] = rowIsLockedByAnother($probe, $assignmentId);
        }
    });

    $act();

    return $seen;
}

function attachBody(): array
{
    return ['title' => 'Campaign addendum', 'body_markdown' => 'You agree to deliver one Reel by the due date.'];
}

beforeEach(function (): void {
    Feature::define(PerCampaignContractEnabled::NAME, true);
    Storage::fake('media');
});

it('PROBE: the offer edit holds the assignment row lock while it re-checks and writes', function (): void {
    ['assignment' => $assignment, 'admin' => $admin] = offerEditSetup(AssignmentStatus::Accepted);

    $seen = probeWhileAppLocks($assignment->id, fn () => app(AssignmentOfferEditor::class)->edit($assignment, 150_00, $admin));

    expect($seen)->toBe([true], 'the second connection was NOT refused: the edit does not hold the row')
        ->and(CampaignAssignment::query()->withoutGlobalScopes()->find($assignment->id)?->agreed_fee_minor_units)->toBe(150_00);
});

it('PROBE: the contract attach holds the assignment row lock while it creates the contract', function (): void {
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin] = offerEditSetup(AssignmentStatus::Accepted);
    $url = "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments/{$assignment->ulid}/contract/attach";

    $seen = probeWhileAppLocks($assignment->id, fn () => $this->actingAs($admin)->postJson($url, attachBody())->assertCreated());

    expect($seen)->toBe([true], 'the second connection was NOT refused: the attach does not hold the row')
        ->and(Contract::query()->count())->toBe(1);
});

it('HOLDER: an offer edit waits behind a lock another connection holds, and does not write around it', function (): void {
    ['assignment' => $assignment, 'admin' => $admin] = offerEditSetup(AssignmentStatus::Accepted);
    $holder = secondConnection();

    $holder->beginTransaction();
    try {
        $holder->select('select id from campaign_assignments where id = ? for update', [$assignment->id]);
        DB::statement("set lock_timeout = '300ms'");

        $started = microtime(true);
        $threw = null;
        try {
            app(AssignmentOfferEditor::class)->edit($assignment, 150_00, $admin);
        } catch (QueryException $e) {
            $threw = $e;
        }
        $waited = microtime(true) - $started;
    } finally {
        $holder->rollBack();
        DB::statement('set lock_timeout = 0');
    }

    expect($threw)->toBeInstanceOf(QueryException::class)
        ->and($threw?->getCode())->toBe('55P03')
        ->and($waited)->toBeGreaterThan(0.25)
        ->and(CampaignAssignment::query()->withoutGlobalScopes()->find($assignment->id)?->agreed_fee_minor_units)->toBe(100_00);
});

it('HOLDER: a contract attach waits behind a lock another connection holds, and creates nothing', function (): void {
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin] = offerEditSetup(AssignmentStatus::Accepted);
    $url = "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments/{$assignment->ulid}/contract/attach";
    $holder = secondConnection();

    $holder->beginTransaction();
    try {
        $holder->select('select id from campaign_assignments where id = ? for update', [$assignment->id]);
        DB::statement("set lock_timeout = '300ms'");

        $this->withoutExceptionHandling();
        $threw = null;
        try {
            $this->actingAs($admin)->postJson($url, attachBody());
        } catch (QueryException $e) {
            $threw = $e;
        }
    } finally {
        $holder->rollBack();
        DB::statement('set lock_timeout = 0');
    }

    expect($threw)->toBeInstanceOf(QueryException::class)
        ->and($threw?->getCode())->toBe('55P03')
        ->and(Contract::query()->count())->toBe(0);
});
