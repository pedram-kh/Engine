<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * A reference link of a concept (PL-2.3). The server never fetches the URL; the domain is derived on read.
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $wave_id
 * @property string $market
 * @property int $concept_id
 * @property string $url
 * @property string $title
 * @property int $position
 */
final class WaveConceptLink extends Model
{
    use BelongsToAgency;
    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = ['agency_id', 'wave_id', 'market', 'concept_id', 'url', 'title', 'position'];
}
