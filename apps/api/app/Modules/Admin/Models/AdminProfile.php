<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Modules\Admin\Database\Factories\AdminProfileFactory;
use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property AdminRole $admin_role
 * @property array<int, string>|null $ip_allowlist
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class AdminProfile extends Model
{
    /** @use HasFactory<AdminProfileFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'admin_role',
        'ip_allowlist',
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
            'admin_role' => AdminRole::class,
            'ip_allowlist' => 'array',
        ];
    }

    protected static function newFactory(): AdminProfileFactory
    {
        return AdminProfileFactory::new();
    }
}
