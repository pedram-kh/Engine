<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Features;

use Closure;

/**
 * AH-119 Messages v2, Stage A — the unified agency inbox: `GET agencies/{agency}/message-inbox`, the
 * relationship-owner reassign endpoint, and the unified inbox UI. OFF at deploy; armed after the web
 * deploy (plan P7). The additive response fields (brand `color`, relation `owner`) are present with the
 * flag off so an older bundle ignores them.
 *
 * Default state = OFF.
 */
final class MessagesV2InboxEnabled
{
    /** Snake-cased registry name (docs/feature-flags.md). Every `Feature::active(...)` MUST use it. */
    public const NAME = 'messages_v2_inbox_enabled';

    /** MUST return a Closure (Pennant stores a non-Closure second argument as the literal value). */
    public static function default(): Closure
    {
        return static fn (mixed $scope = null): bool => false;
    }
}
