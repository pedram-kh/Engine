<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Enums;

/** The current status of a concept (PL-2.3). A decision (`approved`, `changes_requested`) is recorded by the agency. */
enum ConceptStatus: string
{
    case Awaiting = 'awaiting';
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';
}
