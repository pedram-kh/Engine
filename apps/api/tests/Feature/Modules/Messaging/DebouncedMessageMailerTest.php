<?php

declare(strict_types=1);

use App\Modules\Creators\Features\MissingCreatorMailsEnabled;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Mail\NewMessageMail;
use App\Modules\Messaging\Models\MessageEmailDebounce;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Services\DebouncedMessageMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-083 (⑧) — S6: the §5.34 debounce disjoint set against
 * {@see DebouncedMessageMailer} directly (the service in isolation; S8 covers
 * the same set again through the real `sendHumanMessage()` dispatch paths).
 *
 * The five-member disjoint set (kickoff): first-unread emails ·
 * within-30-silent · after-30-re-emails · per-recipient independence ·
 * flag-OFF total mail silence.
 */
function debounceMailable(): NewMessageMail
{
    return new NewMessageMail(
        recipientName: 'Maria',
        senderName: 'Bright Harbour',
        context: 'campaign',
        counterpartyName: 'Autumn UGC push',
        assignmentUlid: '01ASSIGNMENTULIDXXXXXXXXXX',
    );
}

it('first-unread emails — a brand new (thread, recipient) pair queues immediately', function (): void {
    Mail::fake();
    Feature::activate(MissingCreatorMailsEnabled::NAME);
    $thread = MessageThread::factory()->create();
    $recipient = User::factory()->creator()->create();

    $sent = app(DebouncedMessageMailer::class)->maybeSend($thread, $recipient, debounceMailable());

    expect($sent)->toBeTrue();
    Mail::assertQueued(NewMessageMail::class, 1);
    expect(MessageEmailDebounce::query()->count())->toBe(1);
});

it('within-30-silent — a second message inside the 30-minute window does NOT re-email', function (): void {
    Mail::fake();
    Feature::activate(MissingCreatorMailsEnabled::NAME);
    $thread = MessageThread::factory()->create();
    $recipient = User::factory()->creator()->create();
    $mailer = app(DebouncedMessageMailer::class);

    $mailer->maybeSend($thread, $recipient, debounceMailable());
    Carbon::setTestNow(now()->addMinutes(15));
    $second = $mailer->maybeSend($thread, $recipient, debounceMailable());

    expect($second)->toBeFalse();
    Mail::assertQueued(NewMessageMail::class, 1);
    Carbon::setTestNow();
});

it('after-30-re-emails — a message sent 31 minutes later re-arms and emails again', function (): void {
    Mail::fake();
    Feature::activate(MissingCreatorMailsEnabled::NAME);
    $thread = MessageThread::factory()->create();
    $recipient = User::factory()->creator()->create();
    $mailer = app(DebouncedMessageMailer::class);

    $mailer->maybeSend($thread, $recipient, debounceMailable());
    Carbon::setTestNow(now()->addMinutes(31));
    $second = $mailer->maybeSend($thread, $recipient, debounceMailable());

    expect($second)->toBeTrue();
    Mail::assertQueued(NewMessageMail::class, 2);
    expect(MessageEmailDebounce::query()->count())->toBe(1);
    Carbon::setTestNow();
});

it('per-recipient independence — two recipients on the SAME thread get independent windows', function (): void {
    Mail::fake();
    Feature::activate(MissingCreatorMailsEnabled::NAME);
    $thread = MessageThread::factory()->create();
    $recipientOne = User::factory()->creator()->create();
    $recipientTwo = User::factory()->creator()->create();
    $mailer = app(DebouncedMessageMailer::class);

    $mailer->maybeSend($thread, $recipientOne, debounceMailable());
    Carbon::setTestNow(now()->addMinutes(15));
    // Recipient two's FIRST message inside recipient one's window — still
    // recipient two's own first-unread, so it must send regardless.
    $sentForTwo = $mailer->maybeSend($thread, $recipientTwo, debounceMailable());

    expect($sentForTwo)->toBeTrue();
    Mail::assertQueued(NewMessageMail::class, 2);
    expect(MessageEmailDebounce::query()->count())->toBe(2);
    Carbon::setTestNow();
});

it('flag-OFF — total mail silence, and the debounce table is NEVER touched (break-revert anchor)', function (): void {
    Mail::fake();
    expect(Feature::active(MissingCreatorMailsEnabled::NAME))->toBeFalse();
    $thread = MessageThread::factory()->create();
    $recipient = User::factory()->creator()->create();

    $sent = app(DebouncedMessageMailer::class)->maybeSend($thread, $recipient, debounceMailable());

    expect($sent)->toBeFalse();
    Mail::assertNothingQueued();
    expect(MessageEmailDebounce::query()->count())->toBe(0);
});

it('the atomic upsert never double-sends for two calls resolving the same window decision', function (): void {
    Mail::fake();
    Feature::activate(MissingCreatorMailsEnabled::NAME);
    $thread = MessageThread::factory()->create();
    $recipient = User::factory()->creator()->create();
    $mailer = app(DebouncedMessageMailer::class);

    // Two calls with no time advance — the same shape a race would produce
    // (two near-simultaneous messages evaluating the same debounce key).
    $first = $mailer->maybeSend($thread, $recipient, debounceMailable());
    $second = $mailer->maybeSend($thread, $recipient, debounceMailable());

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse();
    Mail::assertQueued(NewMessageMail::class, 1);
    expect(MessageEmailDebounce::query()->count())->toBe(1);
});

it('the flag suppresses mail regardless of the debounce window state (a stale ON-then-OFF row is honoured)', function (): void {
    Mail::fake();
    Feature::activate(MissingCreatorMailsEnabled::NAME);
    $thread = MessageThread::factory()->create();
    $recipient = User::factory()->creator()->create();
    app(DebouncedMessageMailer::class)->maybeSend($thread, $recipient, debounceMailable());

    Feature::deactivate(MissingCreatorMailsEnabled::NAME);
    Carbon::setTestNow(now()->addMinutes(31));
    $afterFlagOff = app(DebouncedMessageMailer::class)->maybeSend($thread, $recipient, debounceMailable());

    expect($afterFlagOff)->toBeFalse();
    Mail::assertQueued(NewMessageMail::class, 1);
    Carbon::setTestNow();
});
