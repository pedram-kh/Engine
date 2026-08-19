<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Database\Factories\MessageEmailDebounceFactory;
use App\Modules\Messaging\Services\DebouncedMessageMailer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * The per-(thread, recipient) debounce stamp for the immediate-message email
 * (AH-083, ⑧). One row per pair that has EVER received a debounced email; the
 * row is read and rewritten in place by {@see DebouncedMessageMailer}
 * on every re-arm — this is the `user_notification_preferences`
 * composite-unique family, NOT the once-only `campaign_job_notifications`
 * stamp family.
 *
 * `thread` is a genuine `morphTo()` over either {@see MessageThread} (a
 * campaign-assignment thread) or {@see RelationshipThread} (a 1:1
 * agency↔creator DM) — the two thread models this table's `thread_type` /
 * `thread_id` pair can point at. No `Relation::morphMap()` is registered
 * anywhere in the app, so `thread_type` stores the raw model FQCN, matching
 * the `notifications.subject_type` precedent.
 *
 * @property int $id
 * @property string $thread_type
 * @property int $thread_id
 * @property int $recipient_user_id
 * @property Carbon $last_emailed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class MessageEmailDebounce extends Model
{
    /** @use HasFactory<MessageEmailDebounceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'thread_type',
        'thread_id',
        'recipient_user_id',
        'last_emailed_at',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function thread(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_emailed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): MessageEmailDebounceFactory
    {
        return MessageEmailDebounceFactory::new();
    }
}
