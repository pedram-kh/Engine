<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Models;

use App\Core\Tenancy\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The audio or the overlay of a hook in one language (PL-3.2a): `language = 'en'` is English. An empty text is no row.
 * `origin` is `manual` only until PL-9.
 *
 * @property int $id
 * @property int $agency_id
 * @property int $wave_id
 * @property int $script_id
 * @property int $hook_id
 * @property string $field
 * @property string $language
 * @property string $body
 * @property string $origin
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read WaveScriptHook $hook
 */
final class WaveScriptHookText extends Model
{
    use BelongsToAgency;

    public const AUDIO = 'audio';

    public const OVERLAY = 'overlay';

    /**
     * @var list<string>
     */
    protected $fillable = ['agency_id', 'wave_id', 'script_id', 'hook_id', 'field', 'language', 'body', 'origin'];

    /** @return BelongsTo<WaveScriptHook, $this> */
    public function hook(): BelongsTo
    {
        return $this->belongsTo(WaveScriptHook::class, 'hook_id');
    }
}
