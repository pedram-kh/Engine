<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Models\MessageEmailDebounce;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\RelationshipThread;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-083 (⑧) — S5: the `message_email_debounces` table, model, and factory.
 * Not the §5.34 debounce-behavior set itself (that lands in S8 against the
 * real dispatch paths) — this pins the SCHEMA: the morphTo shape (C7), the
 * composite-unique constraint, and that both thread models can occupy the
 * polymorphic side.
 */
it('stores a row against a MessageThread (campaign-assignment) via a real morphTo', function (): void {
    $thread = MessageThread::factory()->create();
    $recipient = User::factory()->creator()->create();

    $row = MessageEmailDebounce::factory()->create([
        'thread_type' => MessageThread::class,
        'thread_id' => $thread->id,
        'recipient_user_id' => $recipient->id,
        'last_emailed_at' => now(),
    ]);

    $loadedThread = $row->thread;
    $loadedRecipient = $row->recipient;

    expect($loadedThread)->toBeInstanceOf(MessageThread::class)
        ->and($loadedRecipient)->toBeInstanceOf(User::class);
    expect($loadedThread instanceof MessageThread ? $loadedThread->id : null)->toBe($thread->id);
    expect($loadedRecipient instanceof User ? $loadedRecipient->id : null)->toBe($recipient->id);
});

it('stores a row against a RelationshipThread (1:1 DM) via the SAME morphTo column pair', function (): void {
    $thread = RelationshipThread::factory()->create();
    $recipient = User::factory()->creator()->create();

    $row = MessageEmailDebounce::factory()->create([
        'thread_type' => RelationshipThread::class,
        'thread_id' => $thread->id,
        'recipient_user_id' => $recipient->id,
        'last_emailed_at' => now(),
    ]);

    $loadedThread = $row->thread;

    expect($loadedThread)->toBeInstanceOf(RelationshipThread::class);
    expect($loadedThread instanceof RelationshipThread ? $loadedThread->id : null)->toBe($thread->id);
});

it('enforces the (thread_type, thread_id, recipient_user_id) composite-unique constraint', function (): void {
    $thread = MessageThread::factory()->create();
    $recipient = User::factory()->creator()->create();

    MessageEmailDebounce::factory()->create([
        'thread_type' => MessageThread::class,
        'thread_id' => $thread->id,
        'recipient_user_id' => $recipient->id,
        'last_emailed_at' => now(),
    ]);

    expect(fn () => MessageEmailDebounce::query()->create([
        'thread_type' => MessageThread::class,
        'thread_id' => $thread->id,
        'recipient_user_id' => $recipient->id,
        'last_emailed_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('allows the SAME thread to carry independent rows per recipient (composite key includes recipient)', function (): void {
    $thread = MessageThread::factory()->create();
    $recipientOne = User::factory()->creator()->create();
    $recipientTwo = User::factory()->creator()->create();

    MessageEmailDebounce::factory()->create([
        'thread_type' => MessageThread::class,
        'thread_id' => $thread->id,
        'recipient_user_id' => $recipientOne->id,
        'last_emailed_at' => now(),
    ]);
    MessageEmailDebounce::factory()->create([
        'thread_type' => MessageThread::class,
        'thread_id' => $thread->id,
        'recipient_user_id' => $recipientTwo->id,
        'last_emailed_at' => now(),
    ]);

    expect(MessageEmailDebounce::query()->where('thread_id', $thread->id)->count())->toBe(2);
});

it('updates last_emailed_at IN PLACE on a re-arm — the row is never inserted twice for the same triple', function (): void {
    $thread = MessageThread::factory()->create();
    $recipient = User::factory()->creator()->create();
    $firstEmail = now()->subMinutes(45);

    $row = MessageEmailDebounce::query()->updateOrCreate(
        ['thread_type' => MessageThread::class, 'thread_id' => $thread->id, 'recipient_user_id' => $recipient->id],
        ['last_emailed_at' => $firstEmail],
    );

    $reArmed = now();
    MessageEmailDebounce::query()->updateOrCreate(
        ['thread_type' => MessageThread::class, 'thread_id' => $thread->id, 'recipient_user_id' => $recipient->id],
        ['last_emailed_at' => $reArmed],
    );

    expect(MessageEmailDebounce::query()->where('thread_id', $thread->id)->count())->toBe(1);
    $row->refresh();
    expect($row->last_emailed_at->diffInSeconds($reArmed))->toBeLessThan(2);
});
