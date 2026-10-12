<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Database\Factories\PushDeliveryFactory;
use App\Modules\Notifications\Push\PushDeliveryRecorder;
use App\Modules\Notifications\Push\PushOutcome;
use App\Modules\Notifications\Push\PushPlatform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One push send ATTEMPT and what FCM said about it. Backs `push_deliveries`
 * (AH-102b). Written only by {@see PushDeliveryRecorder}; append-only.
 *
 * NEVER STORE (and never add a column for): the FCM device token, the message
 * title/body/data, the user's IP or user-agent. The row identifies a delivery
 * by user, device row id, platform, notification type and the notification
 * ULID — enough to answer "what was sent and what happened", nothing a leak
 * could turn into a credential or a message transcript.
 *
 * Tenancy: user-global — no `agency_id`.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $user_push_token_id
 * @property PushPlatform $platform
 * @property string $notification_type
 * @property string|null $notification_ulid
 * @property PushOutcome $outcome
 * @property int $attempt
 * @property bool $final
 * @property string|null $code
 * @property int|null $http_status
 * @property string|null $detail
 * @property Carbon $created_at
 */
final class PushDelivery extends Model
{
    /** @use HasFactory<PushDeliveryFactory> */
    use HasFactory;

    /** Append-only: there is no `updated_at`. `created_at` is set explicitly by the recorder. */
    public $timestamps = false;

    protected $table = 'push_deliveries';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'user_push_token_id',
        'platform',
        'notification_type',
        'notification_ulid',
        'outcome',
        'attempt',
        'final',
        'code',
        'http_status',
        'detail',
        'created_at',
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
            'platform' => PushPlatform::class,
            'outcome' => PushOutcome::class,
            'attempt' => 'integer',
            'final' => 'boolean',
            'http_status' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function newFactory(): PushDeliveryFactory
    {
        return PushDeliveryFactory::new();
    }
}
