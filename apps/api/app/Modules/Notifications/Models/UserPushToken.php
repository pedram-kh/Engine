<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Database\Factories\UserPushTokenFactory;
use App\Modules\Notifications\Push\PushPlatform;
use App\Modules\Notifications\Push\PushTokenRegistry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One device's push token, bound to the bearer (personal access token) it
 * registered under. Backs `user_push_tokens` (AH-102).
 *
 * Do NOT query this table for "who can receive a push" — that question has one
 * answer, {@see PushTokenRegistry::liveTokensFor()}, which applies the
 * liveness rules (not invalidated, bearer unexpired, user not soft-deleted).
 *
 * Tenancy: user-global (like `user_notification_preferences`) — no
 * `agency_id`; own-record only.
 *
 * @property int $id
 * @property int $user_id
 * @property int $personal_access_token_id
 * @property PushPlatform $platform
 * @property string $token
 * @property string|null $app_version
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $invalidated_at
 * @property string|null $invalidation_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class UserPushToken extends Model
{
    /** @use HasFactory<UserPushTokenFactory> */
    use HasFactory;

    protected $table = 'user_push_tokens';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'personal_access_token_id',
        'platform',
        'token',
        'app_version',
        'last_seen_at',
        'invalidated_at',
        'invalidation_reason',
    ];

    /**
     * The device token is a credential-adjacent value; never serialise it by
     * accident.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PersonalAccessToken, $this>
     */
    public function personalAccessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => PushPlatform::class,
            'last_seen_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    protected static function newFactory(): UserPushTokenFactory
    {
        return UserPushTokenFactory::new();
    }
}
