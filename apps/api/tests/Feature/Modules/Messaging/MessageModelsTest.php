<?php

declare(strict_types=1);

use App\Modules\Messaging\Enums\MessageKind;
use App\Modules\Messaging\Enums\MessageSenderRole;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageReadReceipt;
use App\Modules\Messaging\Models\MessageThread;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('thread factory derives agency_id from its assignment and auto-fills a ulid', function (): void {
    $thread = MessageThread::factory()->create();

    expect($thread->ulid)->not->toBeEmpty()
        ->and($thread->agency_id)->toBe($thread->assignment?->agency_id);
});

it('message casts sender_role + kind to enums and decodes attachments json', function (): void {
    $message = Message::factory()->attachmentOnly([
        ['s3_path' => 'messages/x/y.pdf', 'mime_type' => 'application/pdf', 'name' => 'y.pdf', 'size_bytes' => 10],
    ])->create();

    expect($message->kind)->toBe(MessageKind::AttachmentOnly)
        ->and($message->sender_role)->toBe(MessageSenderRole::AgencyUser)
        ->and($message->attachments)->toBeArray()
        ->and($message->attachments[0]['mime_type'] ?? null)->toBe('application/pdf');
});

it('a system message has a null sender (D-2) and a system_event_key', function (): void {
    $message = Message::factory()->system('assignment.draft_approved')->create();

    expect($message->sender_user_id)->toBeNull()
        ->and($message->sender_role)->toBe(MessageSenderRole::System)
        ->and($message->kind)->toBe(MessageKind::System)
        ->and($message->isSystem())->toBeTrue()
        ->and($message->system_event_key)->toBe('assignment.draft_approved');
});

it('read receipts are unique per (message, user) — a duplicate insert is rejected', function (): void {
    $receipt = MessageReadReceipt::factory()->create();

    expect(fn () => MessageReadReceipt::factory()->create([
        'message_id' => $receipt->message_id,
        'user_id' => $receipt->user_id,
    ]))->toThrow(QueryException::class);
});

it('thread → messages → receipts relations resolve', function (): void {
    $thread = MessageThread::factory()->create();
    $message = Message::factory()->create(['thread_id' => $thread->id]);
    MessageReadReceipt::factory()->create(['message_id' => $message->id]);

    expect($thread->messages)->toHaveCount(1)
        ->and($thread->latestMessage?->id)->toBe($message->id)
        ->and($message->thread?->id)->toBe($thread->id)
        ->and($message->readReceipts)->toHaveCount(1);
});
