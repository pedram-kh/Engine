<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Strategy\Services\ScriptCodes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A B-roll row of a LINE (PL-3.2b). The code `{line number}_{n}` is computed from the positions (see
 * {@see ScriptCodes}); it is never stored. The description is English only.
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $wave_id
 * @property int $script_id
 * @property int $line_id
 * @property int $position
 * @property string|null $shot_type
 * @property string|null $description
 */
final class WaveScriptBroll extends Model
{
    use BelongsToAgency;
    use HasUlid;

    protected $table = 'wave_script_broll';

    /**
     * @var list<string>
     */
    protected $fillable = ['agency_id', 'wave_id', 'script_id', 'line_id', 'position', 'shot_type', 'description'];

    /** @return BelongsTo<WaveScriptLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(WaveScriptLine::class, 'line_id');
    }
}
