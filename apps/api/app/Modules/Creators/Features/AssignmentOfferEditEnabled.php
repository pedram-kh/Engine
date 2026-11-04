<?php

declare(strict_types=1);

namespace App\Modules\Creators\Features;

use Closure;

/**
 * AH-118 item 2 — the agency's edit of an assignment's offer amount: the endpoint, its notification and its mail leg. One flag for all of them, so an edit is never made without the creator being told. OFF at deploy.
 *
 * Gates the edit endpoint (404 while off), the in-app and push notification, the mail and the history line, all of which are written only by the edit itself. Gates nothing that already exists: re-invite, re-offer and the invite flow are unaffected.
 *
 * Default state = OFF. Lives in the CREATORS feature registry despite gating a
 * CAMPAIGNS-module surface — the existing one-registry convention.
 */
final class AssignmentOfferEditEnabled
{
    /**
     * Snake-cased registry name (docs/feature-flags.md). Every
     * `Feature::active(...)` call site MUST reference this constant.
     */
    public const NAME = 'assignment_offer_edit_enabled';

    /**
     * Default resolver. MUST return a Closure (Pennant stores a non-Closure
     * second argument of `define()` as the literal value).
     */
    public static function default(): Closure
    {
        return static fn (mixed $scope = null): bool => false;
    }
}
