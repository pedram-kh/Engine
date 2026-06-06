<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Support;

use App\Console\Commands\SendMessageDigests;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Services\MessageDigestService;

/**
 * One user's daily unread-messages digest payload (Sprint 11, D-9) — the
 * tenancy-correct aggregate {@see MessageDigestService}
 * builds and the {@see SendMessageDigests} command mails.
 *
 * Only built for a recipient who (a) has ≥1 unread HUMAN message across their
 * accessible threads and (b) has the `digest` channel enabled for their
 * messaging type (opt-in, default OFF). System messages and the recipient's own
 * sends are excluded from the count.
 */
final class PendingMessageDigest
{
    /**
     * @param  list<array{campaign: string, counterparty: string, unread: int}>  $lines
     */
    public function __construct(
        public readonly User $recipient,
        public readonly int $totalUnread,
        public readonly array $lines,
    ) {}
}
