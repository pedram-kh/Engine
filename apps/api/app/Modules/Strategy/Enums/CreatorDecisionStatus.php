<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Enums;

/** The client's decision on a creator (PL-3.1, P7). A creator with no decision has none yet: there is no "awaiting" row. */
enum CreatorDecisionStatus: string
{
    case Approved = 'approved';
    case SuggestAnother = 'suggest_another';
}
