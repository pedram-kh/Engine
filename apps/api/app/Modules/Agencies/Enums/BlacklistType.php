<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Enums;

use App\Modules\Creators\Enums\BlockType;

/**
 * Whether a creator blacklist is a HARD exclusion or a SOFT warning
 * (Sprint 7, D-1/D-6).
 *
 *   hard → EXCLUDE. Drops the creator from the blacklisting agency's discovery
 *          (B1, calling-agency-scoped) and BLOCKS new connection requests (B2).
 *          A real, enforced block.
 *   soft → WARN ONLY. A visible warning on the detail / discovery surfaces; the
 *          creator stays matchable — NOT excluded from discovery, NOT a send
 *          block. Mirrors the availability {@see BlockType}
 *          hard/soft semantics so the distinction is consistent app-wide (D-1).
 *
 * One enum shared by BOTH blacklist surfaces (D-6): cast on
 * agency_creator_relations.blacklist_type AND brand_creator_blacklists.blacklist_type
 * (same hard/soft domain). Stored as varchar(8).
 */
enum BlacklistType: string
{
    case Hard = 'hard';
    case Soft = 'soft';
}
