<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/MessageRevisionFixtures.php';

afterEach(fn () => Carbon::setTestNow());

/*
 * AH-119 B2 — message.edited / message.deleted carry ids and nothing else: never the text, old or new.
 */

it('audits one message.edited per real edit with id-only metadata, and no body text anywhere in the row', function (string $surface): void {
    $w = revWorld();
    $s = revSurface($w, $surface, 'ORIGINAL-SECRET');

    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'REPLACEMENT-SECRET'])->assertOk();

    $rows = AuditLog::query()->where('action', AuditAction::MessageEdited)->get();
    expect($rows)->toHaveCount(1);
    $row = $rows->sole();
    expect($row->actor_id)->toBe($s['actor']->id)
        ->and($row->agency_id)->toBe($w['agency']->id)
        ->and($row->subject_ulid)->toBe($s['message']->ulid)
        ->and(array_keys((array) $row->metadata))->toEqualCanonicalizing(['message_ulid', 'thread_ulid'])
        ->and(($row->metadata ?? [])['message_ulid'] ?? null)->toBe($s['message']->ulid)
        ->and($row->before)->toBeNull()->and($row->after)->toBeNull()->and($row->reason)->toBeNull()
        ->and(json_encode($row->getAttributes()))->not->toContain('SECRET');
})->with(revSurfaceDataset());

it('audits message.deleted with id-only metadata and no text', function (string $surface): void {
    $w = revWorld();
    $s = revSurface($w, $surface, 'WITHDRAWN-SECRET');

    $this->actingAs($s['actor'])->deleteJson($s['url'])->assertOk();

    $row = AuditLog::query()->where('action', AuditAction::MessageDeleted)->sole();
    expect(array_keys((array) $row->metadata))->toEqualCanonicalizing(['message_ulid', 'thread_ulid'])
        ->and($row->subject_ulid)->toBe($s['message']->ulid)
        ->and($row->agency_id)->toBe($w['agency']->id)
        ->and(json_encode($row->getAttributes()))->not->toContain('SECRET');
})->with(revSurfaceDataset());

it('audits nothing for a no-op edit or any refused request', function (string $surface): void {
    $w = revWorld();
    $s = revSurface($w, $surface, 'same');

    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'same'])->assertOk();              // no-op
    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => ''])->assertUnprocessable();       // validation
    Carbon::setTestNow(now()->addMinutes(16));
    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'late'])->assertUnprocessable();   // window
    $this->actingAs($s['actor'])->deleteJson($s['url'])->assertOk();
    $this->actingAs($s['actor'])->deleteJson($s['url'])->assertUnprocessable();                      // already deleted

    expect(AuditLog::query()->where('action', AuditAction::MessageEdited)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::MessageDeleted)->count())->toBe(1);
})->with(revSurfaceDataset());
