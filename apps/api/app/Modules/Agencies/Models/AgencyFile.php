<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Agencies\Database\Factories\AgencyFileFactory;
use App\Modules\Agencies\Enums\AgencyFileCategory;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A single agency-owned document (AH-091, Agency Files library chunk A).
 * Standalone — unlike every prior upload path, an AgencyFile has no parent
 * (creator, brand, assignment); it belongs only to its agency. Per
 * docs/reviews/agency-files-plan.md §2.1.
 *
 * Deletion (D5): `hasAttachments()` is chunk A's seam for chunk B, which will
 * attach these rows to campaigns — it returns `false` unconditionally today
 * because no attachment relation exists yet. The delete flow
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
     * Whether this file is currently attached to any campaign. Vacuously
     * `false` in chunk A — no attachment relation exists yet. Chunk B is the
     * intended source of truth: it replaces this method's body with a real
     * query against its attachment table, turning this from an inert seam
     * into a live delete guard (409 `agency_file.attached`) without changing
     * any caller.
     */
    public function hasAttachments(): bool
    {
        return false;
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
