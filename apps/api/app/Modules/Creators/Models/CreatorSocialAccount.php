<?php

declare(strict_types=1);

namespace App\Modules\Creators\Models;

use App\Core\Concerns\HasUlid;
use App\Modules\Creators\Database\Factories\CreatorSocialAccountFactory;
use App\Modules\Creators\Enums\SocialPlatform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Social-media account connected to a Creator.
 *
 * Encryption (#23 of data-model spec):
 *   - oauth_access_token
 *   - oauth_refresh_token
 *
 * The `encrypted` cast wraps the value in Laravel's authenticated cipher
 * envelope on write and unwraps on read. Break-revert verified by
 * tests/Feature/Modules/Creators/EncryptionCastsTest.php.
 *
 * @property int $id
 * @property string $ulid
 * @property int $creator_id
 * @property SocialPlatform $platform
 * @property string $platform_user_id
 * @property string $handle
 * @property string $profile_url
 * @property string|null $oauth_access_token
 * @property string|null $oauth_refresh_token
 * @property Carbon|null $oauth_expires_at
 * @property Carbon|null $last_synced_at
 * @property string $sync_status
 * @property array<string, mixed>|null $metrics
 * @property array<string, mixed>|null $audience_demographics
 * @property bool $is_primary
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
final class CreatorSocialAccount extends Model
{
    /** @use HasFactory<CreatorSocialAccountFactory> */
    use HasFactory;

    use HasUlid;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'creator_id',
        'platform',
        'platform_user_id',
        'handle',
        'profile_url',
        'oauth_access_token',
        'oauth_refresh_token',
        'oauth_expires_at',
        'last_synced_at',
        'sync_status',
        'metrics',
        'audience_demographics',
        'is_primary',
    ];

    /**
     * @return BelongsTo<Creator, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => SocialPlatform::class,
            'oauth_access_token' => 'encrypted',
            'oauth_refresh_token' => 'encrypted',
            'oauth_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'metrics' => 'array',
            'audience_demographics' => 'array',
            'is_primary' => 'boolean',
        ];
    }

    protected static function newFactory(): CreatorSocialAccountFactory
    {
        return CreatorSocialAccountFactory::new();
    }
}
