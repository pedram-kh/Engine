<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Features;

use Closure;

/**
 * AH-115 L4 — the creator's unified inbox: `GET creators/me/message-inbox` and
 * `GET creators/me/message-search`, and the creator inbox UI that reads them. OFF at deploy; armed after
 * the web deploy and the native build 5 walk. Independent of `messages_v2_inbox_enabled` (the agency
 * inbox) and of `messages_v2_edit_search_enabled`: it gates the creator inbox and its search only.
 *
 * Default state = OFF.
 */
final class MessagesV2CreatorInboxEnabled
{
    /** Snake-cased registry name (docs/feature-flags.md). Every `Feature::active(...)` MUST use it. */
    public const NAME = 'messages_v2_creator_inbox_enabled';

    /** MUST return a Closure (Pennant stores a non-Closure second argument as the literal value). */
    public static function default(): Closure
    {
        return static fn (mixed $scope = null): bool => false;
    }
}
