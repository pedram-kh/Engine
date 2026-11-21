<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Strategy\Database\Factories\WaveConceptFactory;
use App\Modules\Strategy\Enums\ConceptOrigin;
use App\Modules\Strategy\Enums\ConceptStatus;
use App\Modules\Strategy\Enums\FunnelStage;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One concept of one market of a wave (PL-2.3). `position` is the "n" of "CONCEPT n"; `status` is the current status
 * (the history is {@see WaveConceptDecision}).
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $wave_id
 * @property int $market_id
 * @property-read WaveMarket $market
 * @property int $persona_id
 * @property int $position
 * @property string $title
 * @property FunnelStage|null $funnel_stage
 * @property string|null $funnel_note
 * @property string|null $product
 * @property string|null $angle
 * @property string|null $primary_message
 * @property string|null $hypothesis
 * @property string|null $hook
 * @property string|null $format_visual
 * @property list<string>|null $key_points
 * @property string|null $payoff
 * @property string|null $cta
 * @property ConceptStatus $status
 * @property ConceptOrigin $origin
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read WavePersona $persona
 */
final class WaveConcept extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<WaveConceptFactory> */
    use HasFactory;

    use HasUlid;

    /** The text columns a person edits (the nine card fields plus the funnel note). */
    public const array TEXT_FIELDS = ['product', 'angle', 'primary_message', 'hypothesis', 'hook', 'format_visual', 'payoff', 'cta'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id', 'wave_id', 'market_id', 'persona_id', 'position', 'title', 'funnel_stage', 'funnel_note', 'product',
        'angle', 'primary_message', 'hypothesis', 'hook', 'format_visual', 'key_points', 'payoff', 'cta', 'status', 'origin',
    ];

    /**
     * PL-2.4: the market (a named group of countries) this row belongs to.
     *
     * @return BelongsTo<WaveMarket, $this>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(WaveMarket::class, 'market_id');
    }

    /** @return BelongsTo<Wave, $this> */
    public function wave(): BelongsTo
    {
        return $this->belongsTo(Wave::class)->withTrashed();
    }

    /** @return BelongsTo<WavePersona, $this> */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(WavePersona::class, 'persona_id');
    }

    /** @return HasMany<WaveConceptUsp, $this> */
    public function chips(): HasMany
    {
        return $this->hasMany(WaveConceptUsp::class, 'concept_id')->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<WaveConceptImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(WaveConceptImage::class, 'concept_id')->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<WaveConceptLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(WaveConceptLink::class, 'concept_id')->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<WaveConceptDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(WaveConceptDecision::class, 'concept_id')->orderByDesc('id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'funnel_stage' => FunnelStage::class,
            'key_points' => 'array',
            'status' => ConceptStatus::class,
            'origin' => ConceptOrigin::class,
        ];
    }

    protected static function newFactory(): WaveConceptFactory
    {
        return WaveConceptFactory::new();
    }
}
