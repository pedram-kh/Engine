<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A reference image of a hook or of a line (PL-3.2b): exactly one of `hook_id` and `line_id` is set. `pending` is a reservation
 * made at upload init (it counts against the limits until `expires_at`); `ready` carries the stored object's own MIME and size.
 * `storage_key` is never exposed.
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $wave_id
 * @property int $script_id
 * @property int|null $hook_id
 * @property int|null $line_id
 * @property int $position
 * @property string $storage_key
 * @property string|null $thumb_key
 * @property string $original_filename
 * @property string|null $mime_type
 * @property int $declared_bytes
 * @property int|null $size_bytes
 * @property string $status
 * @property Carbon|null $expires_at
 * @property int|null $uploaded_by_user_id
 */
final class WaveScriptImage extends Model
{
    use BelongsToAgency;
    use HasUlid;

    public const string PENDING = 'pending';

    public const string READY = 'ready';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id', 'wave_id', 'script_id', 'hook_id', 'line_id', 'position', 'storage_key', 'thumb_key', 'original_filename',
        'mime_type', 'declared_bytes', 'size_bytes', 'status', 'expires_at', 'uploaded_by_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['declared_bytes' => 'integer', 'size_bytes' => 'integer', 'position' => 'integer', 'expires_at' => 'datetime'];
    }
}
