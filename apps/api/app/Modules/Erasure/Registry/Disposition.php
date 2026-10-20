<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Registry;

/**
 * The seven codes of the approved PII map (docs/reviews/account-deletion-pii-map.md §0).
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
}
