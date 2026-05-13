<?php

declare(strict_types=1);

namespace App\Modules\Brands\Enums;

/**
 * Operational status of a Brand.
 *
 * Sprint 2 ships `active` and `archived`. The `archived` state excludes
 * the brand from default list views; the brand's data and relationships
 * (campaigns, assignments) remain intact.
 *
 * Note: `deleted_at` (SoftDeletes) is separate from `status` — it
 * represents permanent GDPR-erasure-style deletion, while `archived`
 * represents an operational state change.
 *
 * Honest deviation #D1 (Sprint 2 Chunk 1): The `brands` table in
 * docs/03-DATA-MODEL.md does not include a `status` column — it uses
 * `deleted_at` for soft delete. Sprint 2 kickoff pre-answers "status
 * field, not soft delete" and the campaign entity precedent (status +
 * deleted_at) justifies adding this column. Building per the kickoff's
 * pre-answered decision, flagging as structurally-correct minimal
 * extension.
 */
enum BrandStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
