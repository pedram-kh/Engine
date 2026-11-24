<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Strategy\Database\Factories\WaveScriptHookFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One hook of a script (PL-3.2a). The hook letter (A, B, C ...) is NOT stored: it is `position` (0 = A). The spoken audio and
 * the on-screen overlay are rows of `wave_script_hook_texts`, one per field and language.
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $wave_id
 * @property int $script_id
 * @property int $position
 * @property string|null $shot_type
 * @property string|null $visual_description
 * @property int $revision
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read WaveScript $script
 */
final class WaveScriptHook extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<WaveScriptHookFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = ['agency_id', 'wave_id', 'script_id', 'position', 'shot_type', 'visual_description', 'revision'];

    /** @return BelongsTo<WaveScript, $this> */
    public function script(): BelongsTo
    {
        return $this->belongsTo(WaveScript::class, 'script_id');
    }

    /** @return HasMany<WaveScriptHookText, $this> */
    public function texts(): HasMany
    {
        return $this->hasMany(WaveScriptHookText::class, 'hook_id');
    }

    /** The derived letter: position 0 is A. Past Z (never: the cap is 6) it would wrap, which cannot happen. */
    public function letter(): string
    {
        return chr(65 + ($this->position % 26));
    }

    protected static function newFactory(): WaveScriptHookFactory
    {
        return WaveScriptHookFactory::new();
    }
}
