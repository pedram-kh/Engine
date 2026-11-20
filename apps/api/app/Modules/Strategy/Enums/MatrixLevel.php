<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Enums;

/**
 * The level of one messaging-matrix cell (PL-2.2). Priority = lead with this, Support = worth including, Low = not the
 * motivator. "Not set" is not a level: it is no row.
 */
enum MatrixLevel: string
{
    case Priority = 'priority';
    case Support = 'support';
    case Low = 'low';

    /** Priority and Support cells feed the persona's angles; Low does not. */
    public function feedsAngle(): bool
    {
        return $this !== self::Low;
    }
}
