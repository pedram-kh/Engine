<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Features;

use Closure;

/**
 * AH-119 Messages v2, Stage B — edit and delete of one's own message (eight routes) and the agency
 * message search (`GET agencies/{agency}/message-search`), plus their UI. OFF at deploy; armed ONLY
 * after TestFlight build 4 is live (Q9): a build-3 install renders a deleted message as a blank
 * bubble. Off: every route answers 404 and nothing is written.
 *
 * Default state = OFF.
 */
final class MessagesV2EditSearchEnabled
{
    /** Snake-cased registry name (docs/feature-flags.md). Every `Feature::active(...)` MUST use it. */
    public const NAME = 'messages_v2_edit_search_enabled';

    /** MUST return a Closure (Pennant stores a non-Closure second argument as the literal value). */
    public static function default(): Closure
    {
        return static fn (mixed $scope = null): bool => false;
    }
}
