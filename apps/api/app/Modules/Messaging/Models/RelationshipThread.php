<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Creators\Models\Creator;
use App\Modules\Messaging\Database\Factories\RelationshipThreadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One 1:1 message thread per connected agency↔creator pair (AH-010a, D1).
 *
 * A deliberate PARALLEL spine to {@see MessageThread} — NOT a generalization
 * (the campaign `messages.thread_id` FK forbids sharing the message table
 * without a campaign-path change; AH-010 Step-0). It owns its own
 * {@see RelationshipMessage} / {@see RelationshipMessageReadReceipt} layer.
 *
 * Tenancy: tenant-scoped via BelongsToAgency (`agency_id`). The agency surface
 * reads it under the standard tenancy stack; the creator surface resolves it
 * via `withoutGlobalScope(BelongsToAgencyScope)` + structural ownership through
 * `creator_id` (the campaign creator-surface precedent).
 *
 * The `(agency_id, creator_id)` UNIQUE backs firstOrCreate idempotency across
 * both initiating sides (D3). Unlike the assignment thread there is NO
 * assignment-status terminal: the thread is open while the relation is active,
 * and sends are blocked at the gate (blacklist / non-roster), not by a thread
 * column (D6).
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $creator_id
 * @property Carbon|null $last_message_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class RelationshipThread extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<RelationshipThreadFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'creator_id',
        'last_message_at',
    ];

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return BelongsTo<Creator, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    /**
     * @return HasMany<RelationshipMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(RelationshipMessage::class, 'thread_id');
    }

    /**
     * @return HasOne<RelationshipMessage, $this>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(RelationshipMessage::class, 'thread_id')->latestOfMany();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    protected static function newFactory(): RelationshipThreadFactory
    {
        return RelationshipThreadFactory::new();
    }
}
