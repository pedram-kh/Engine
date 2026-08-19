<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Mail;

use App\Modules\Creators\Features\MissingCreatorMailsEnabled;
use App\Modules\Messaging\Services\DebouncedMessageMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The daily aggregated unread-messages digest (Sprint 11, D-8/D-9) — ONE email
 * per opted-in user with unread messages.
 *
 * Sprint 11's D-8 read ("there is deliberately no immediate per-message
 * email") was **reversed by product decision, AH-083, 2026-08-19**: an
 * immediate, 30-minute-debounced message email now ships alongside this
 * digest, flag-gated behind
 * {@see MissingCreatorMailsEnabled} — see
 * {@see NewMessageMail} and
 * {@see DebouncedMessageMailer}. The two are
 * deliberately NOT cross-suppressed (AH-083 kickoff Q6) — this digest is
 * unaware of the debounced email's send history and vice versa — so a
 * creator opted into both channels may see the same unread thread reported
 * twice in short order. Accepted as a rare, cosmetic duplication rather than
 * a coupling worth buying; named here so a future reader does not mistake it
 * for a bug.
 *
 * Queued + rendered through the shared `catalyst` markdown theme. Renders in
 * the application default locale (`en`) for all recipients — no per-recipient
 * locale is set at the send site (SendMessageDigests.php) by deliberate decision.
 * See docs/tech-debt.md "Digest + agency-invite emails are English-only".
 */
final class UnreadMessagesDigestMail extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{campaign: string, counterparty: string, unread: int}>  $lines
     */
    public function __construct(
        public readonly string $recipientName,
        public readonly int $totalUnread,
        public readonly array $lines,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('messages.digest.subject'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.messaging.unread-digest',
            with: [
                'recipientName' => $this->recipientName,
                'totalUnread' => $this->totalUnread,
                'threadCount' => count($this->lines),
                'lines' => $this->lines,
                'messagesUrl' => $this->buildMessagesUrl(),
            ],
        );
    }

    private function buildMessagesUrl(): string
    {
        $base = rtrim((string) config('app.frontend_main_url', 'http://127.0.0.1:5173'), '/');

        return $base.'/notifications';
    }
}
