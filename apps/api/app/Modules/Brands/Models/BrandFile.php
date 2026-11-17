<?php

declare(strict_types=1);

namespace App\Modules\Brands\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Brands\Database\Factories\BrandFileFactory;
use App\Modules\Brands\Enums\BrandFileType;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A client file on a brand (PL-1.1). Mirrors AgencyFile with a brand parent; not part of the Files library.
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $brand_id
 * @property string $original_filename
 * @property string $storage_key
 * @property string $mime_type
 * @property int $size_bytes
 * @property BrandFileType $file_type
 * @property int|null $uploaded_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Brand $brand
 * @property-read User|null $uploadedBy
 */
final class BrandFile extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<BrandFileFactory> */
    use HasFactory;

    use HasUlid;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'brand_id',
        'original_filename',
        'storage_key',
        'mime_type',
        'size_bytes',
        'file_type',
        'uploaded_by_user_id',
    ];

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'file_type' => BrandFileType::class,
        ];
    }

    protected static function newFactory(): BrandFileFactory
    {
        return BrandFileFactory::new();
    }
}
