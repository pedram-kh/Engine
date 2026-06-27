<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The daily aggregated unread-messages digest (Sprint 11, D-8/D-9) — ONE email
 * per opted-in user with unread messages. This is messaging's email channel:
 * there is deliberately no immediate per-message email (D-8 spec-divergence).
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
