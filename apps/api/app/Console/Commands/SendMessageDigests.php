<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Messaging\Mail\UnreadMessagesDigestMail;
use App\Modules\Messaging\Services\MessageDigestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Send the daily unread-messages digest (Sprint 11, D-9) — the app's FIRST
 * scheduled command (registered ->daily() via withSchedule in bootstrap/app.php).
 *
 * It asks {@see MessageDigestService} for the tenancy-correct set of opted-in
 * recipients with unread messages (the digest channel is opt-in / default OFF,
 * and the aggregate gates each recipient on isChannelEnabled itself — the
 * digest path does NOT ride NotificationService::notify(), which is in-app
 * only). One aggregated email per recipient; nothing for opt-out / no-unread.
 */
final class SendMessageDigests extends Command
{
    protected $signature = 'messages:send-digest';

    protected $description = 'Email each opted-in user a daily digest of their unread messages.';

    public function handle(MessageDigestService $digests): int
    {
        $pending = $digests->pendingDigests();

        foreach ($pending as $digest) {
            Mail::to($digest->recipient->email)->queue(new UnreadMessagesDigestMail(
                recipientName: $digest->recipient->name,
                totalUnread: $digest->totalUnread,
                lines: $digest->lines,
            ));
        }

        $this->info(sprintf('Queued %d message digest(s).', count($pending)));

        return self::SUCCESS;
    }
}
