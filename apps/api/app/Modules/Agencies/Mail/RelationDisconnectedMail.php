<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Admin disconnect notification — AH-051 (D-7). Sent to BOTH parties of a
 * severed relationship (the creator, and each active member of the agency).
 *
 * ONE mailable, direction-agnostic: the recipient's own name is the greeting,
 * the OTHER party is the `:counterparty`. For the creator recipient the
 * counterparty is the agency name; for an agency-member recipient it is the
 * creator's display name. The mail is deliberately informational only (no CTA
 * button, no reason text — the reason is audit-only) with a "contact support
 * if unexpected" line.
 *
 * Queued (ShouldQueue), localized via Mail::locale() at queue time to the
 * recipient's preferred language, rendered through the shared `catalyst`
 * markdown theme. Half of the D-7 dual-emit (in-app RelationDisconnected is
 * the other half).
 *
 * Real provider is deferred — config/mail.php default is `log`. Verified via
 * Mail::fake() (dispatch + content + locale), not a real inbox.
 */
final class RelationDisconnectedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $counterpartyName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('creators.disconnected.email.subject', ['counterparty' => $this->counterpartyName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.agencies.relation-disconnected',
            with: [
                'recipientName' => $this->recipientName,
                'counterpartyName' => $this->counterpartyName,
            ],
        );
    }
}
