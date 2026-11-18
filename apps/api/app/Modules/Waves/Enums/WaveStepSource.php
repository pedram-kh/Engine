<?php

declare(strict_types=1);

namespace App\Modules\Waves\Enums;

/**
 * Who last set a step's status (PL-1.3 plan section 1.8, ruling F1). Any API status change sets `Manual`; PL-2 writes
 * `System` when a real strategy screen sets a strategy row.
 */
enum WaveStepSource: string
{
    case System = 'system';
    case Manual = 'manual';
}
