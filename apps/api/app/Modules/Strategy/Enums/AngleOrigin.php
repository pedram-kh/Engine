<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Enums;

/** Where an angle came from. PL-2.1 knows only `manual`; a later chunk may add `matrix`, PL-9 may add `ai`. */
enum AngleOrigin: string
{
    case Manual = 'manual';
}
