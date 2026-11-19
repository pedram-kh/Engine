<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Strategy\Database\Factories\WavePersonaAngleFactory;
use App\Modules\Strategy\Enums\AngleOrigin;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One angle: a persona's one-line reason to care about a USP of the same wave (PL-2.1). The composite foreign keys make
 * the same-wave rule true in the database.
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $wave_id
 * @property int $persona_id
 * @property int $usp_id
 * @property string $explanation
 * @property AngleOrigin $origin
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read WavePersona $persona
 * @property-read WaveUsp $usp
 */
final class WavePersonaAngle extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<WavePersonaAngleFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'wave_id',
        'persona_id',
        'usp_id',
        'explanation',
        'origin',
    ];

    /**
     * @return BelongsTo<WavePersona, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(WavePersona::class, 'persona_id');
    }

    /**
     * @return BelongsTo<WaveUsp, $this>
     */
    public function usp(): BelongsTo
    {
        return $this->belongsTo(WaveUsp::class, 'usp_id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'origin' => AngleOrigin::class,
        ];
    }

    protected static function newFactory(): WavePersonaAngleFactory
    {
        return WavePersonaAngleFactory::new();
    }
}
