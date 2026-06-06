<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Messaging\Database\Factories\MessageThreadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One message thread per CampaignAssignment (Sprint 11, D-3). See
 * docs/03-DATA-MODEL.md §11.
 *
 * Tenancy (D-16): tenant-scoped via BelongsToAgency (`agency_id`). The agency
 * surfaces read this under the standard tenancy stack; the creator surface
 * resolves it via `withoutGlobalScope(BelongsToAgencyScope)` + structural
 * ownership through the assignment (the connection/assignment-controller
 * precedent). Messages + receipts scope through this thread.
 *
 * The `assignment_id` UNIQUE backs firstOrCreate idempotency across the three
 * create sites (the invite listener, the defensive create before a
 * system-message write, the lazy GET create).
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $assignment_id
 * @property Carbon|null $last_message_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class MessageThread extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<MessageThreadFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * Assignment statuses that block a HUMAN send (Sprint 11, D-13 + Q2). The
     * thread is ALWAYS readable, and SYSTEM messages still write on terminal
     * events regardless — only human sends are gated.
     *
     * Deliberately NARROWER than {@see AssignmentStatus::isTerminal()}: that set
     * includes `payment_released`, but per Q2 a paid-out assignment stays OPEN
     * for post-delivery wrap-up (asset handoff, final notes). The gate is the
     * three "engagement ended badly / was called off" terminals only.
     *
     * @var list<AssignmentStatus>
     */
    public const array HUMAN_SEND_BLOCKED_STATUSES = [
        AssignmentStatus::Declined,
        AssignmentStatus::Rejected,
        AssignmentStatus::Cancelled,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'assignment_id',
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
     * @return BelongsTo<CampaignAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(CampaignAssignment::class, 'assignment_id');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'thread_id');
    }

    /**
     * @return HasOne<Message, $this>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class, 'thread_id')->latestOfMany();
    }

    /**
     * Whether a human send is currently blocked (D-13 + Q2). Reads the parent
     * assignment's status; system writes ignore this.
     */
    public function humanSendBlocked(): bool
    {
        $assignment = $this->assignment;

        return $assignment !== null
            && in_array($assignment->status, self::HUMAN_SEND_BLOCKED_STATUSES, true);
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

    protected static function newFactory(): MessageThreadFactory
    {
        return MessageThreadFactory::new();
    }
}
