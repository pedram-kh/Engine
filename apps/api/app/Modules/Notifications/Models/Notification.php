<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Core\Concerns\HasUlid;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Database\Factories\NotificationFactory;
use App\Modules\Notifications\Enums\NotificationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * An in-app notification addressed to a single User (S11.0 Chunk 1, D-4).
 *
 * Tenancy (D-9): user-level, ABOVE tenancy. This model deliberately OMITS the
 * BelongsToAgency trait — there is no `agency_id`. Isolation is
 * `recipient_user_id = auth user` at the controller; user A can never read
 * user B's notifications.
 *
 * Append-then-mark-read lifecycle: `created_at` only (no `updated_at`), and
 * `read_at` is the single post-insert mutation. Marking an already-read row is
 * idempotent (§5.6) — see {@see self::markRead()}.
 *
 * @property int $id
 * @property string $ulid
 * @property int $recipient_user_id
 * @property int|null $actor_user_id
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property NotificationType $type
 * @property array<string, mixed>|null $data
 * @property Carbon|null $read_at
 * @property Carbon $created_at
 */
final class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * Append-then-mark-read: created_at is set on insert; there is no
     * updated_at column. read_at is mutated directly.
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'recipient_user_id',
        'actor_user_id',
        'subject_type',
        'subject_id',
        'type',
        'data',
        'read_at',
        'created_at',
    ];

    /**
     * The User who receives this notification. Always set (recipients are
     * always Users in P1 — a plain FK, not a polymorphic notifiable).
     *
     * @return BelongsTo<User, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /**
     * The User who triggered this notification. NULL for system notifications
     * (the audit actor_id-nullable precedent).
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * The polymorphic subject (the assignment / creator / message the
     * notification is about). May be null for system notifications.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Idempotent mark-read (§5.6): re-marking an already-read row is a no-op —
     * the original read_at is preserved and no write occurs.
     */
    public function markRead(): void
    {
        if ($this->read_at !== null) {
            return;
        }

        $this->read_at = now();
        $this->save();
    }

    /**
     * Mark one recipient's unread MESSAGE notifications for one subject read
     * (AH-108). Called when the recipient opens that thread, so the bell, the
     * Messages badge and the app-icon badge stop counting messages they have
     * just read. `read_at` is the single source of truth for all three.
     *
     * Keyed by (recipient, subject_type, subject_id, type IN message types), never
     * by the JSON `data` payload: it uses the `idx_notifications_subject` index
     * and cannot match another thread's row or a non-message row on the same
     * subject (an `assignment.*` alert on the same assignment stays unread).
     * Rows already read are excluded by `read_at IS NULL`, so a re-run moves no
     * timestamp.
     *
     * A plain query-builder update on purpose: `Notification` has no model events
     * or observers to bypass, and one UPDATE beats loading rows to save them.
     *
     * @param  string  $subjectType  the subject's `getMorphClass()`
     * @return int rows newly marked
     */
    /**
     * How many notifications this user has not read: the bell's number and the
     * push icon badge (AH-108). `read_at` is the single source of truth.
     */
    public static function unreadCountFor(User $recipient): int
    {
        return self::query()
            ->where('recipient_user_id', $recipient->getKey())
            ->whereNull('read_at')
            ->count();
    }

    public static function markMessageNotificationsRead(User $recipient, string $subjectType, int $subjectId): int
    {
        return self::query()
            ->where('recipient_user_id', $recipient->getKey())
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->whereIn('type', array_map(
                static fn (NotificationType $type): string => $type->value,
                NotificationType::messageTypes(),
            ))
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'data' => 'array',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function newFactory(): NotificationFactory
    {
        return NotificationFactory::new();
    }
}
