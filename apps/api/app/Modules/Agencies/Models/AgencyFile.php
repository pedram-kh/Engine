<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Agencies\Database\Factories\AgencyFileFactory;
use App\Modules\Agencies\Enums\AgencyFileCategory;
use App\Modules\Campaigns\Models\CampaignAttachment;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A single agency-owned document (AH-091, Agency Files library chunk A).
 * Standalone — unlike every prior upload path, an AgencyFile has no parent
 * (creator, brand, assignment); it belongs only to its agency. Per
 * docs/reviews/agency-files-plan.md §2.1.
 *
 * Deletion (D5): `hasAttachments()` was chunk A's inert seam (`return false`
 * unconditionally, no attachment relation yet) and is now chunk B's real
 * guard (AH-092) against {@see CampaignAttachment}. The delete flow
 * (AgencyFileUploadService::delete()) hard-deletes the S3 object BEFORE
 * soft-deleting this row (object-first, the CreatorPortfolioItem precedent),
 * so a failed object delete never orphans bytes behind an invisible row.
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property string $original_filename
 * @property string $storage_key
 * @property string $mime_type
 * @property int $size_bytes
 * @property AgencyFileCategory $category
 * @property int|null $uploaded_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $uploadedBy
 */
final class AgencyFile extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<AgencyFileFactory> */
    use HasFactory;

    use HasUlid;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'original_filename',
        'storage_key',
        'mime_type',
        'size_bytes',
        'category',
        'uploaded_by_user_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * Every campaign attachment row pointing at this file (chunk B, AH-092).
     * Unconditional — no visibility, campaign-status, or campaign-soft-delete
     * filter — because {@see hasAttachments()} must answer "is this file
     * linked anywhere at all", not "is it linked somewhere currently
     * creator-visible". No SoftDeletes clause is needed either: detach is a
     * hard delete of the linkage (CampaignAttachment carries no `deleted_at`),
     * so every row this relation returns is live by construction.
     *
     * @return HasMany<CampaignAttachment, $this>
     */
    public function campaignAttachments(): HasMany
    {
        return $this->hasMany(CampaignAttachment::class);
    }

    /**
     * Whether this file is currently attached to any campaign (chunk B,
     * AH-092 — this is the real query chunk A's docblock deferred to here).
     * The delete flow (AgencyFileController::destroy()) calls this BEFORE
     * ever touching S3 or this row, and answers 409 `agency_file.attached`
     * on `true` — the FK's `restrictOnDelete()` on `agency_file_id` is only
     * the hard-delete backstop; this guard is what a normal soft-delete
     * actually hits.
     */
    public function hasAttachments(): bool
    {
        return $this->campaignAttachments()->exists();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'category' => AgencyFileCategory::class,
        ];
    }

    protected static function newFactory(): AgencyFileFactory
    {
        return AgencyFileFactory::new();
    }
}
