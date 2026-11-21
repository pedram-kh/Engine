<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Enums;

/** Where a concept came from. Only `manual` exists; PL-9 widens the database CHECK and adds `agent`. */
enum ConceptOrigin: string
{
    case Manual = 'manual';
}
