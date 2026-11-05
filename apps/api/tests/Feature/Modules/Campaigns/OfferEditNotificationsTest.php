<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Mail\OfferEditedMail;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Models\Message;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/OfferEditHelpers.php';

/**
 * AH-118 A6 — what an offer edit leaves behind: the audit row, the in-app row
 * (which drives the push), the mail, and the history line both sides read.
 * Everything is written by the same call that changes the amount, and nothing
 * is written when the edit is refused.
 */

/**
 * Edit through the real endpoint. The creator's user is returned so tests can
 * look at what reached them.
 *
 * @return array{assignment: CampaignAssignment, creatorUser: User, response: TestResponse<JsonResponse>, s: array{agency: Agency, campaign: Campaign, assignment: CampaignAssignment, admin: User}}
 */
function editOffer(mixed $test, int $amount = 250_00, ?AssignmentStatus $status = AssignmentStatus::Invited, ?Closure $before = null): array
{
    $s = offerEditSetup($status ?? AssignmentStatus::Invited);
    $creatorUser = $s['assignment']->creator()->firstOrFail()->user()->firstOrFail();
    $creatorUser->forceFill(['preferred_language' => 'de'])->save();

    if ($before !== null) {
        $before($creatorUser, $s);
    }

    $response = $test->actingAs($s['admin'])->patchJson(
        offerEditUrl($s['agency'], $s['campaign'], $s['assignment']),
        ['agreed_fee_minor_units' => $amount],
    );

    return ['assignment' => $s['assignment'], 'creatorUser' => $creatorUser, 'response' => $response, 's' => $s];
}

/**
 * @param  TestResponse<JsonResponse>  $response
 * @return list<array{attributes: array<string, mixed>}>
 */
function messageLines(TestResponse $response): array
{
    /** @var list<array{attributes: array<string, mixed>}> $rows */
    $rows = $response->json('data');

    return $rows;
}

// ── audit ───────────────────────────────────────────────────────────────────

it('writes one assignment.offer_edited audit row with the amounts and no free text', function (): void {
    ['assignment' => $assignment, 'response' => $response] = editOffer($this);
    $response->assertOk();

    $row = AuditLog::query()->where('action', AuditAction::AssignmentOfferEdited->value)->sole();

    expect($row->metadata)->toBe([
        'from' => 'invited',
        'previous_agreed_fee_minor_units' => 100_00,
        'agreed_fee_minor_units' => 250_00,
        'agreed_fee_currency' => 'EUR',
    ])->and($row->subject_id)->toBe($assignment->id);
});

// ── in-app + push ───────────────────────────────────────────────────────────

it('writes the creator an in-app notification carrying the amounts and the route key', function (): void {
    ['assignment' => $assignment, 'creatorUser' => $creatorUser] = editOffer($this);

    $row = Notification::query()->where('recipient_user_id', $creatorUser->id)->where('type', NotificationType::AssignmentOfferEdited->value)->sole();

    $data = $row->data ?? [];

    expect($data)->toMatchArray([
        'assignment_ulid' => $assignment->ulid,
        'previous_amount' => 100_00,
        'new_amount' => 250_00,
        'currency' => 'EUR',
    ])->and($data['campaign_name'] ?? null)->toBeString();
});

it('writes no in-app row when the creator has muted in_app for the type', function (): void {
    editOffer($this, before: function ($creatorUser): void {
        app(NotificationService::class)->setPreference($creatorUser, NotificationType::AssignmentOfferEdited, NotificationChannel::InApp, false);
    });

    expect(Notification::query()->where('type', NotificationType::AssignmentOfferEdited->value)->count())->toBe(0);
});

// ── mail ────────────────────────────────────────────────────────────────────

it('queues the mail to the creator, localised, with both amounts', function (): void {
    ['assignment' => $assignment, 'creatorUser' => $creatorUser] = editOffer($this);

    Mail::assertQueued(OfferEditedMail::class, static fn (OfferEditedMail $m): bool => $m->hasTo($creatorUser->email)
        && $m->locale === 'de'
        && $m->previousAmount === '100.00 EUR'
        && $m->newAmount === '250.00 EUR'
        && $m->assignmentUlid === $assignment->ulid);
});

it('sends no mail when the creator has switched the email channel off for the type', function (): void {
    editOffer($this, before: function ($creatorUser): void {
        app(NotificationService::class)->setPreference($creatorUser, NotificationType::AssignmentOfferEdited, NotificationChannel::Email, false);
    });

    Mail::assertNotQueued(OfferEditedMail::class);
    // …and the in-app row still lands: the channels are independent.
    expect(Notification::query()->where('type', NotificationType::AssignmentOfferEdited->value)->count())->toBe(1);
});

// ── the history line ────────────────────────────────────────────────────────

it('writes a system message with the amounts, and both sides read it', function (): void {
    ['assignment' => $assignment, 'creatorUser' => $creatorUser, 's' => $s] = editOffer($this);

    $message = Message::query()->where('system_event_key', 'assignment.offer_edited')->sole();
    // toEqual: Postgres jsonb does not keep key order.
    expect($message->system_event_data)->toEqual(['previous_amount' => 100_00, 'new_amount' => 250_00, 'currency' => 'EUR'])
        ->and($message->body)->toBeNull()
        ->and($message->sender_user_id)->toBeNull();

    // The creator reads it …
    $creatorLines = messageLines($this->actingAs($creatorUser)->getJson("/api/v1/creators/me/assignments/{$assignment->ulid}/messages")->assertOk());
    expect(array_column(array_column($creatorLines, 'attributes'), 'system_event_key'))->toContain('assignment.offer_edited');

    // … and so does the agency.
    $agencyLines = messageLines($this->actingAs($s['admin'])->getJson("/api/v1/agencies/{$s['agency']->ulid}/campaigns/{$s['campaign']->ulid}/assignments/{$assignment->ulid}/messages")->assertOk());
    $line = array_values(array_filter($agencyLines, static fn (array $l): bool => $l['attributes']['system_event_key'] === 'assignment.offer_edited'))[0];
    expect($line['attributes']['system_event_data'])->toEqual(['previous_amount' => 100_00, 'new_amount' => 250_00, 'currency' => 'EUR']);
});

it('emits system_event_data on every message, null for ordinary and lifecycle lines', function (): void {
    ['assignment' => $assignment, 'creatorUser' => $creatorUser] = editOffer($this);

    $this->actingAs($creatorUser)->postJson("/api/v1/creators/me/assignments/{$assignment->ulid}/messages", ['body' => 'Thanks!'])->assertCreated();

    $rows = messageLines($this->actingAs($creatorUser)->getJson("/api/v1/creators/me/assignments/{$assignment->ulid}/messages"));

    foreach ($rows as $row) {
        expect($row['attributes'])->toHaveKey('system_event_data');
    }

    $human = array_values(array_filter($rows, static fn (array $l): bool => $l['attributes']['body'] === 'Thanks!'))[0];
    expect($human['attributes']['system_event_data'])->toBeNull();
});

// ── nothing is written when the edit is refused ─────────────────────────────

it('leaves no audit row, notification, mail or history line behind a refused edit', function (AssignmentStatus $status, int $amount): void {
    ['response' => $response] = editOffer($this, $amount, $status);
    $response->assertStatus(422);

    expect(AuditLog::query()->where('action', AuditAction::AssignmentOfferEdited->value)->count())->toBe(0)
        ->and(Notification::query()->where('type', NotificationType::AssignmentOfferEdited->value)->count())->toBe(0)
        ->and(Message::query()->where('system_event_key', 'assignment.offer_edited')->count())->toBe(0);
    Mail::assertNotQueued(OfferEditedMail::class);
})->with([
    'locked (countered)' => [AssignmentStatus::Countered, 250_00],
    'unchanged amount' => [AssignmentStatus::Invited, 100_00],
]);

it('does not touch the assignment status or fire the transition fan-out (no board, no other notifications)', function (): void {
    ['assignment' => $assignment] = editOffer($this, status: AssignmentStatus::Accepted);

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::Accepted)
        ->and(Notification::query()->where('type', '!=', NotificationType::AssignmentOfferEdited->value)->count())->toBe(0);
});

// ── unavailable creators (AH-104b-del C4) ───────────────────────────────────

it('still edits but tells nothing to a creator who is unavailable to agencies', function (string $state): void {
    ['response' => $response, 'creatorUser' => $creatorUser, 'assignment' => $assignment] = editOffer($this, before: function (User $creatorUser, array $s) use ($state): void {
        match ($state) {
            'erased' => $s['assignment']->creator()->firstOrFail()->forceFill(['erased_at' => now()])->save(),
            'pending-deletion' => $creatorUser->forceFill(['deletion_requested_at' => now()])->save(),
            default => $creatorUser->forceFill(['erasure_started_at' => now()])->save(),
        };
    });

    $response->assertOk();
    expect($assignment->fresh()?->agreed_fee_minor_units)->toBe(250_00)
        ->and(AuditLog::query()->where('action', AuditAction::AssignmentOfferEdited->value)->count())->toBe(1)
        ->and(Notification::query()->where('recipient_user_id', $creatorUser->id)->where('type', NotificationType::AssignmentOfferEdited->value)->count())->toBe(0);
    Mail::assertNothingQueued();
    Mail::assertNothingSent();
})->with(['erased', 'pending-deletion', 'erasure-claimed']);

it('still notifies an ordinary creator (control for the unavailable cases)', function (): void {
    ['creatorUser' => $creatorUser] = editOffer($this);

    expect(Notification::query()->where('recipient_user_id', $creatorUser->id)->where('type', NotificationType::AssignmentOfferEdited->value)->count())->toBe(1);
    Mail::assertQueued(OfferEditedMail::class);
});
