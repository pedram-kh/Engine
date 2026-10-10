<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push;

/**
 * Delivery urgency, vendor-neutral. Each sender maps it to its own wire value.
 */
enum PushPriority: string
{
    case High = 'high';
    case Normal = 'normal';
}
