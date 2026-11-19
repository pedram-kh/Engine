<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Strategy\Database\Factories\WavePersonaFactory;
use App\Modules\Strategy\Enums\PersonaPronoun;
use App\Modules\Strategy\Enums\UspColor;
use App\Modules\Waves\Models\Wave;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One persona of one market of a wave (PL-2.1). `name` is an INVENTED persona name, not a person. The colour is derived
 * from the slot (not stored).
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $wave_id
 * @property string $market
 * @property int $slot
 * @property string $code
 * @property string $title
 * @property string $name
 * @property PersonaPronoun $pronoun
 * @property string|null $summary
 * @property string|null $core_job
 * @property string|null $need
 * @property list<string>|null $drivers
 * @property list<string>|null $goals
 * @property list<string>|null $self_talk
 * @property list<string>|null $barriers
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Wave $wave
 */
final class WavePersona extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<WavePersonaFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'wave_id',
        'market',
        'slot',
        'code',
        'title',
        'name',
        'pronoun',
        'summary',
        'core_job',
        'need',
        'drivers',
        'goals',
        'self_talk',
        'barriers',
    ];

    public function colorKey(): UspColor
    {
        return UspColor::forSlot($this->slot);
    }

    /**
     * @return BelongsTo<Wave, $this>
     */
    public function wave(): BelongsTo
    {
        return $this->belongsTo(Wave::class)->withTrashed();
    }

    /**
     * @return HasMany<WavePersonaAngle, $this>
     */
    public function angles(): HasMany
    {
        return $this->hasMany(WavePersonaAngle::class, 'persona_id')->orderBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'pronoun' => PersonaPronoun::class,
            'drivers' => 'array',
            'goals' => 'array',
            'self_talk' => 'array',
            'barriers' => 'array',
        ];
    }

    protected static function newFactory(): WavePersonaFactory
    {
        return WavePersonaFactory::new();
    }
}
