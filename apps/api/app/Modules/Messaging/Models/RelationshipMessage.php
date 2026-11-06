<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Concerns\HasUlid;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Database\Factories\RelationshipMessageFactory;
use App\Modules\Messaging\Enums\MessageKind;
use App\Modules\Messaging\Enums\MessageSenderRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A single message in a relationship thread (AH-010a, D1). Mirrors
 * {@see Message}, minus the system concerns — relationship threads have NO
 * lifecycle/system messages, so `sender_user_id` is always set (a human author)
 * and there is no `system_event_key`.
 *
 * Tenancy: scopes THROUGH the thread — no `agency_id`. The campaign
 * {@see MessageKind} (`text` / `attachment_only`) and {@see MessageSenderRole}
 * (`creator` / `agency_user`) enums are reused as the shared vocabulary.
 *
 * `deleted_at` is the delete marker (AH-119 B2), as on the campaign table.
 *
 * @property int $id
 * @property string $ulid
 * @property int $thread_id
 * @property int $sender_user_id
 * @property MessageSenderRole $sender_role
 * @property MessageKind $kind
 * @property string|null $body
 * @property array<int, array<string, mixed>>|null $attachments
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $edited_at AH-119 B2: set on every edit.
 * @property string|null $original_body AH-119 B2: the text before the FIRST edit. NEVER on a resource, payload or admin surface.
 * @property int|null $deleted_by_user_id AH-119 B2: the sender who deleted it.
 * @property string|null $deleted_body AH-119 B2: the text withdrawn by a delete. NEVER on a resource, payload or admin surface.
 * @property bool $read_by_counterparty Non-persisted, set by RelationshipMessageService::pageForThread (D10 read tick).
 */
final class RelationshipMessage extends Model
{
    /** @use HasFactory<RelationshipMessageFactory> */
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
    ];

    /**
     * @return BelongsTo<RelationshipThread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(RelationshipThread::class, 'thread_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    /**
     * @return HasMany<RelationshipMessageReadReceipt, $this>
     */
    public function readReceipts(): HasMany
    {
        return $this->hasMany(RelationshipMessageReadReceipt::class, 'message_id');
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
            'edited_at' => 'datetime',
        ];
    }

    protected static function newFactory(): RelationshipMessageFactory
    {
        return RelationshipMessageFactory::new();
    }
}
