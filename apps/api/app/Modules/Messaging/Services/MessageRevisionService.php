<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Enums\MessageKind;
use App\Modules\Messaging\Exceptions\MessageRevisionException;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\RelationshipMessage;
use App\Modules\Messaging\Models\RelationshipThread;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * AH-119 B2 — edit and delete of one's own message, for BOTH message tables (the campaign
 * {@see Message} and the {@see RelationshipMessage}). One place for the rules, so the four route sets
 * cannot drift. Every change happens inside a transaction that takes `lockForUpdate` on the message
 * row first and re-reads it, so an edit racing a delete (or two edits) is serialised and the loser
 * sees the winner's state. State machine (inventory §3):
 *
 *   sent/edited --edit (sender, <= 15 min after created_at, thread open)--> edited
 *   sent/edited --delete (sender, any time, closed thread allowed)--------> deleted (terminal)
 *   deleted --anything--> refused (`message.already_deleted`)
 *
 * Mechanics that matter:
 *  - The window runs from `created_at` on the SERVER clock (`Carbon::setTestNow` in specs); an edit never
 *    extends it.
 *  - The first edit copies the old text into `original_body`; every edit sets `edited_at`.
 *  - A delete moves `body` into `deleted_body`, nulls `body`, sets `deleted_at` and `deleted_by_user_id`;
 *    attachments stay in place (hidden by the resource, purged at erasure, Q4).
 *  - `last_message_at` is NOT touched. NO notification, push or mail is emitted: this service never
 *    calls the notification layer. Unread counts and the digest read the SoftDeletes scope, so a
 *    deleted message leaves them; read receipts are untouched (Q3).
 *  - The audit row carries ids only (`message_ulid`, `thread_ulid`); never any text.
 *  - Not the sender's message, or a system line (no sender): 404 (never 403, never a hint).
 */
final class MessageRevisionService
{
    public const int WINDOW_MINUTES = 15;

    /**
     * @throws MessageRevisionException
     */
    public function edit(MessageThread|RelationshipThread $thread, Message|RelationshipMessage $message, User $actor, string $body): Message|RelationshipMessage
    {
        return DB::transaction(function () use ($thread, $message, $actor, $body): Message|RelationshipMessage {
            $row = $this->lock($thread, $message, $actor);

            if ($row->deleted_at !== null) {
                throw new MessageRevisionException(MessageRevisionException::ALREADY_DELETED, 'This message was already deleted.');
            }
            if ($row->kind !== MessageKind::Text || $row->body === null) {
                throw new MessageRevisionException(MessageRevisionException::NOT_EDITABLE, 'This message cannot be edited.');
            }
            if ($row->created_at->copy()->addMinutes(self::WINDOW_MINUTES)->lt(Carbon::now())) {
                throw new MessageRevisionException(MessageRevisionException::EDIT_WINDOW_CLOSED, 'This message can no longer be edited.');
            }
            if (! $this->threadIsOpen($thread, $actor)) {
                throw new MessageRevisionException(MessageRevisionException::EDIT_THREAD_CLOSED, 'This conversation is closed, so messages cannot be edited.');
            }

            if ($row->body === $body) {
                return $row; // identical text: a no-op, nothing written, nothing audited
            }

            $row->forceFill([
                // Captured on the FIRST edit only: later edits leave the original untouched.
                'original_body' => $row->original_body ?? $row->body,
                'body' => $body,
                'edited_at' => Carbon::now(),
            ])->save();

            $this->audit(AuditAction::MessageEdited, $actor, $thread, $row);

            return $row;
        });
    }

    /**
     * @throws MessageRevisionException
     */
    public function delete(MessageThread|RelationshipThread $thread, Message|RelationshipMessage $message, User $actor): Message|RelationshipMessage
    {
        return DB::transaction(function () use ($thread, $message, $actor): Message|RelationshipMessage {
            $row = $this->lock($thread, $message, $actor);

            if ($row->deleted_at !== null) {
                throw new MessageRevisionException(MessageRevisionException::ALREADY_DELETED, 'This message was already deleted.');
            }

            $row->forceFill([
                'deleted_body' => $row->body,
                'body' => null,
                'deleted_at' => Carbon::now(),
                'deleted_by_user_id' => $actor->id,
            ])->save();

            $this->audit(AuditAction::MessageDeleted, $actor, $thread, $row);

            return $row;
        });
    }

    /**
     * Take the row lock, re-read the row (trashed included, so "already deleted" is distinguishable from
     * "not found") and re-check the invariants that never change: the row belongs to THIS thread and
     * its sender is the actor. A system line has no sender, so it never passes.
     */
    private function lock(MessageThread|RelationshipThread $thread, Message|RelationshipMessage $message, User $actor): Message|RelationshipMessage
    {
        $row = $message::query()->withTrashed()
            ->whereKey($message->getKey())
            ->where('thread_id', $thread->id)
            ->lockForUpdate()
            ->first();

        if ($row === null || $row->sender_user_id === null || $row->sender_user_id !== $actor->id) {
            throw MessageRevisionException::notFound();
        }

        return $row;
    }

    /**
     * Q12: edit needs an OPEN thread. Campaign thread: the assignment is not declined/rejected/cancelled
     * (`humanSendBlocked`). Relationship thread: the status-aware `canMessageRelationship` gate that
     * governs sending, evaluated for the editing user (never re-derived here).
     */
    private function threadIsOpen(MessageThread|RelationshipThread $thread, User $actor): bool
    {
        if ($thread instanceof MessageThread) {
            $thread->loadMissing('assignment');

            return ! $thread->humanSendBlocked();
        }

        $creator = Creator::query()->withTrashed()->find($thread->creator_id);
        $agency = Agency::query()->find($thread->agency_id);

        return $creator !== null && $agency !== null
            && Gate::forUser($actor)->allows('canMessageRelationship', [$creator, $agency]);
    }

    private function audit(AuditAction $action, User $actor, MessageThread|RelationshipThread $thread, Message|RelationshipMessage $message): void
    {
        Audit::log(
            action: $action,
            actor: $actor,
            subject: $message,
            metadata: ['message_ulid' => $message->ulid, 'thread_ulid' => $thread->ulid],
            agencyId: $thread->agency_id,
        );
    }
}
