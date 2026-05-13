<?php

declare(strict_types=1);

namespace App\Modules\Creators\Models;

use App\Core\Concerns\HasUlid;
use App\Modules\Creators\Database\Factories\CreatorPortfolioItemFactory;
use App\Modules\Creators\Enums\PortfolioItemKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Portfolio item attached to a Creator. Up to 10 per creator (enforced
 * at the service layer).
 *
 * For uploaded items: s3_path + thumbnail_path point at the media disk.
 * For link items: external_url is populated; s3_path is null.
 *
 * @property int $id
 * @property string $ulid
 * @property int $creator_id
 * @property PortfolioItemKind $kind
 * @property string|null $title
 * @property string|null $description
 * @property string|null $s3_path
 * @property string|null $external_url
 * @property string|null $thumbnail_path
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property int|null $duration_seconds
 * @property int $position
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
final class CreatorPortfolioItem extends Model
{
    /** @use HasFactory<CreatorPortfolioItemFactory> */
    use HasFactory;

    use HasUlid;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'creator_id',
        'kind',
        'title',
        'description',
        's3_path',
        'external_url',
        'thumbnail_path',
        'mime_type',
        'size_bytes',
        'duration_seconds',
        'position',
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
            'kind' => PortfolioItemKind::class,
            'size_bytes' => 'integer',
            'duration_seconds' => 'integer',
            'position' => 'integer',
        ];
    }

    protected static function newFactory(): CreatorPortfolioItemFactory
    {
        return CreatorPortfolioItemFactory::new();
    }
}
