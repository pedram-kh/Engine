<?php

declare(strict_types=1);

namespace App\Modules\Creators\Concerns;

use App\Modules\Creators\Models\Creator;

/**
 * AH-104b-del (D3, B2): the ONE way an agency-facing resource says "this
 * creator has been erased".
 *
 * An erased creator row keeps its ulid (routes and history key on it) but its
 * name, avatar and every identifier are null. The SPA cannot tell "no name yet"
 * (a prospect mid-wizard) from "erased" by looking at a null, so every agency
 * resource that carries a creator also carries `is_erased`, and the SPA renders
 * a localised "Deleted creator" from it (`creatorLabel()`).
 *
 * Only ERASED is emitted. A creator who has merely asked to delete their account
 * looks exactly as before (a cancelled request must leave no trace); the
 * pending state is never sent to an agency.
 *
 * The architecture spec `AgencyCreatorSurfacesTest` requires this trait (and the
 * `is_erased` key) on every resource in the manifest.
 */
trait EmitsErasedState
{
    protected function creatorIsErased(?Creator $creator): bool
    {
        return $creator instanceof Creator && $creator->isErased();
    }
}
