<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Database\Factories\MessageReadReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A per-user read receipt on a message (Sprint 11). See docs/03-DATA-MODEL.md
 * §11.
 *
 * The `(message_id, user_id)` UNIQUE makes mark-read idempotent (§5.6) — a
 * re-read collides on the unique and is a no-op. The row IS the read event:
 * `read_at` is the only timestamp (no created_at/updated_at).
 *
 * Tenancy (D-16): scopes THROUGH the message → thread. No `agency_id`.
 *
 * @property int $id
 * @property int $message_id
 * @property int $user_id
 * @property Carbon $read_at
 */
final class MessageReadReceipt extends Model
{
    /** @use HasFactory<MessageReadReceiptFactory> */
    use HasFactory;

    /**
     * The row is the read event — read_at only, no created_at/updated_at.
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'message_id',
        'user_id',
        'read_at',
    ];

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    protected static function newFactory(): MessageReadReceiptFactory
    {
        return MessageReadReceiptFactory::new();
    }
}
