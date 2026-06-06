<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Database\Factories\NotificationPreferenceFactory;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single per-user, per-type, per-channel notification toggle (S11.0 Chunk 1,
 * D-7). Backs `user_notification_preferences`.
 *
 * Default resolution is COMPUTED, not stored: a MISSING row resolves to the
 * channel default (`in_app`/`email` ON, `digest` OFF) — see
 * {@see NotificationChannel::defaultEnabled()} and
 * {@see NotificationService}. No per-user
 * row is seeded; a missing row never silently disables an existing email.
 *
 * Tenancy (D-9): user-global. NO BelongsToAgency / agency_id — own-record only.
 *
 * @property int $id
 * @property int $user_id
 * @property NotificationType $type
 * @property NotificationChannel $channel
 * @property bool $is_enabled
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class NotificationPreference extends Model
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use HasFactory;

    protected $table = 'user_notification_preferences';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'type',
        'channel',
        'is_enabled',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'channel' => NotificationChannel::class,
            'is_enabled' => 'boolean',
        ];
    }

    protected static function newFactory(): NotificationPreferenceFactory
    {
        return NotificationPreferenceFactory::new();
    }
}
