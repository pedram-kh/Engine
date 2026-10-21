<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Registry;

/**
 * The seven codes of the approved PII map, plus {@see self::Cancel} (the AH-104b-del rider) (docs/reviews/account-deletion-pii-map.md §0).
 * The string values are the map's own, so a reader can diff the two.
 */
enum Disposition: string
{
    /** Row stays; the column is overwritten with an {@see AnonValue}. */
    case Anon = 'ANON';

    /** Row is deleted (creator-owned satellites, own feed, own device rows). */
    case EraseRow = 'ERASE-ROW';

    /** Row and column untouched, with a reason that goes in the privacy notice. */
    case Keep = 'KEEP';

    /** Row stays; named keys inside a JSON/text column are removed or nulled. */
    case ScrubJson = 'SCRUB-JSON';

    /** Rows deleted because their content matches the subject, not by foreign key. */
    case DelMatch = 'DEL-MATCH';

    /** Nothing to do: the row has a TTL or no personal content. */
    case Expire = 'EXPIRE';

    /** No creator data in this table (map §3.7). */
    case NotApplicable = 'N/A';

    /**
     * Not in the approved map: the AH-104b-del rider. An open (non-terminal) assignment
     * of the erased creator is moved to `cancelled` through the state machine, with a
     * fixed reason and no acting user, so the agency is not left waiting on a person
     * who no longer exists. The row stays (it is an anchor); only its status changes.
     * Executed by `Cancellation\CancelOpenAssignments`, not by the engine's raw writers.
     */
    case Cancel = 'CANCEL';
}
