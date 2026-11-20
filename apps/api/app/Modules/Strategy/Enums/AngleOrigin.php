<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Enums;

/**
 * Where an angle came from. `manual` is typed on the persona card; `matrix` is synced from a Priority or Support cell of
 * the messaging matrix (PL-2.2) and is read-only on the persona endpoints. PL-9 may add `ai`.
 */
enum AngleOrigin: string
{
    case Manual = 'manual';
    case Matrix = 'matrix';
}
