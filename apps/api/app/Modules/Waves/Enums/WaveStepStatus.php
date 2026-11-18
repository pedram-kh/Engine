<?php

declare(strict_types=1);

namespace App\Modules\Waves\Enums;

/**
 * Stored status of a tracker step. "Delayed", "due today" and "up next" are DISPLAY statuses computed from the due
 * date, never stored (PL-1.3 plan section 1.5).
 */
enum WaveStepStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Done = 'done';
}
