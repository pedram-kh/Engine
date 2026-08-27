<?php

declare(strict_types=1);

namespace App\Modules\Identity\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Notice — a platform admin restored this user's 2FA requirement (AH-087).
 *
 * The restoring half of the exemption pair. The user is mailed in BOTH
 * directions: a person whose security posture changes should hear about it
 * whichever way it moved, and a requirement reappearing without warning is
 * how a re-enrollment prompt turns into a support ticket.
 *
 * Same disciplines as its siblings: email-only, no reason quoted, no admin
 * named.
 */
final class MfaExemptionRemovedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $recipientName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('auth.mfa_admin.exemption_removed.subject'),
            tags: ['auth', 'mfa-admin-exemption-removed'],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.identity.mfa-exemption-removed',
            with: [
                'recipientName' => $this->recipientName,
                'appName' => (string) config('app.name'),
            ],
        );
    }
}
