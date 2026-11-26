<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Models;

use App\Core\Tenancy\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The spoken text of a line in one language (PL-3.2b): `en` is English. An empty text is no row. `origin` is `manual` only until PL-9.
 *
 * @property int $id
 * @property int $agency_id
 * @property int $wave_id
 * @property int $script_id
 * @property int $line_id
 * @property string $language
 * @property string $body
 * @property string $origin
 */
final class WaveScriptLineText extends Model
{
    use BelongsToAgency;

    /**
     * @var list<string>
     */
    protected $fillable = ['agency_id', 'wave_id', 'script_id', 'line_id', 'language', 'body', 'origin'];

    /** @return BelongsTo<WaveScriptLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(WaveScriptLine::class, 'line_id');
    }
}
