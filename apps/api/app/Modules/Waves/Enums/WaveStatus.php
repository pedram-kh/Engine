<?php

declare(strict_types=1);

namespace App\Modules\Waves\Enums;

/**
 * Status of a wave. One case in PL-1.1; later chunks add cases (the column is a plain string, so no migration).
 */
enum WaveStatus: string
{
    case Draft = 'draft';
}
