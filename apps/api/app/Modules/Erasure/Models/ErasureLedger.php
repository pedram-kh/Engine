<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Models;

use App\Core\Concerns\HasUlid;
use App\Modules\Erasure\Database\Factories\ErasureLedgerFactory;
use App\Modules\Erasure\Enums\ErasureLedgerState;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One erasure run. Backs `erasure_ledger` (AH-104a).
 *
 * NEVER STORE (and never add a column for): an email, a name, an ip, free text.
 * The row says THAT a run happened for a pseudonymous `user_ulid`, how far it
 * got, and counts. A schema allowlist spec fails if a column is added here.
 *
 * Tenancy: platform-global — no `agency_id`.
 *
 * @property int $id
 * @property string $ulid
 * @property string $user_ulid
 * @property ErasureLedgerState $state
 * @property array<string, mixed>|null $counts
 * @property string|null $digest
 * @property int|null $run_by_user_id
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class ErasureLedger extends Model
{
    /** @use HasFactory<ErasureLedgerFactory> */
    use HasFactory;

    use HasUlid;

    protected $table = 'erasure_ledger';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_ulid',
        'state',
        'counts',
        'digest',
        'run_by_user_id',
        'started_at',
        'completed_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => ErasureLedgerState::class,
            'counts' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ErasureLedgerFactory
    {
        return ErasureLedgerFactory::new();
    }
}
