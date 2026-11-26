<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Strategy\Database\Factories\WaveScriptLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One spoken line of a script (PL-3.2b). The line NUMBER is not stored: it is `position + 2` (the hook is line 1). The spoken text
 * is a row of `wave_script_line_texts` per language; the B-roll rows and the images hang on it. `revision` is the line's own
 * concurrency token.
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $wave_id
 * @property int $script_id
 * @property int $position
 * @property bool $cta
 * @property int $revision
 * @property-read WaveScript $script
 */
final class WaveScriptLine extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<WaveScriptLineFactory> */
    use HasFactory;

    use HasUlid;

    /** The hook is line 1, so the first line of the list is line 2. */
    public const int FIRST_NUMBER = 2;

    /**
     * @var list<string>
     */
    protected $fillable = ['agency_id', 'wave_id', 'script_id', 'position', 'cta', 'revision'];

    public function number(): int
    {
        return $this->position + self::FIRST_NUMBER;
    }

    /** @return BelongsTo<WaveScript, $this> */
    public function script(): BelongsTo
    {
        return $this->belongsTo(WaveScript::class, 'script_id');
    }

    /** @return HasMany<WaveScriptLineText, $this> */
    public function texts(): HasMany
    {
        return $this->hasMany(WaveScriptLineText::class, 'line_id');
    }

    /** @return HasMany<WaveScriptBroll, $this> */
    public function broll(): HasMany
    {
        return $this->hasMany(WaveScriptBroll::class, 'line_id')->orderBy('position')->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['cta' => 'boolean'];
    }

    protected static function newFactory(): WaveScriptLineFactory
    {
        return WaveScriptLineFactory::new();
    }
}
