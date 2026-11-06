<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Concerns\HasUlid;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Database\Factories\MessageFactory;
use App\Modules\Messaging\Enums\MessageKind;
use App\Modules\Messaging\Enums\MessageSenderRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A single message in a thread (Sprint 11). See docs/03-DATA-MODEL.md §11.
 *
 * Tenancy (D-16): scopes THROUGH the thread — no `agency_id`, no
 * BelongsToAgency. Isolation is enforced at the thread.
 *
 * `sender_user_id` is NULLABLE (D-2): system messages (`sender_role = system`,
 * `kind = system`) have no human sender. Human messages always set it.
 *
 * The body text is NEVER stored localized for system messages — `system_event_key`
 * + the thread's assignment context render the string at display time (D-5).
 *
 * `deleted_at` is the delete marker (AH-119 B2): a deleted message keeps its row, `body` is nulled and moved to
 * `deleted_body`. The SoftDeletes scope hides it from unread, digest and search; only the feed and the previews
 * opt in with `withTrashed()` and the resource masks it.
 *
 * @property int $id
 * @property string $ulid
 * @property int $thread_id
 * @property int|null $sender_user_id
 * @property MessageSenderRole $sender_role
 * @property MessageKind $kind
 * @property string|null $body
 * @property array<int, array<string, mixed>>|null $attachments
 * @property string|null $system_event_key
 * @property array<string, mixed>|null $system_event_data
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $edited_at AH-119 B2: set on every edit.
 * @property string|null $original_body AH-119 B2: the text before the FIRST edit. NEVER on a resource, payload or admin surface.
 * @property int|null $deleted_by_user_id AH-119 B2: the sender who deleted it.
 * @property string|null $deleted_body AH-119 B2: the text withdrawn by a delete. NEVER on a resource, payload or admin surface.
 */
final class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    use HasUlid;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'thread_id',
        'sender_user_id',
        'sender_role',
        'kind',
        'body',
        'attachments',
        'system_event_key',
        'system_event_data',
    ];

    /**
     * @return BelongsTo<MessageThread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(MessageThread::class, 'thread_id');
    }

    /**
     * The human author. NULL for system messages (D-2).
     *
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    /**
     * @return HasMany<MessageReadReceipt, $this>
     */
    public function readReceipts(): HasMany
    {
        return $this->hasMany(MessageReadReceipt::class, 'message_id');
    }

    public function isSystem(): bool
    {
        return $this->kind === MessageKind::System;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sender_role' => MessageSenderRole::class,
            'kind' => MessageKind::class,
            'attachments' => 'array',
            'system_event_data' => 'array',
            'edited_at' => 'datetime',
        ];
    }

    protected static function newFactory(): MessageFactory
    {
        return MessageFactory::new();
    }
}
