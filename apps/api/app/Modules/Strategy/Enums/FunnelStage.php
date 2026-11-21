<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Enums;

/** Top, middle or bottom of the funnel (PL-2.3). */
enum FunnelStage: string
{
    case Tofu = 'tofu';
    case Mofu = 'mofu';
    case Bofu = 'bofu';
}
