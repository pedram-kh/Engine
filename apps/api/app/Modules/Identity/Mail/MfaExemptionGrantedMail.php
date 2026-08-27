<?php

declare(strict_types=1);

namespace App\Modules\Identity\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Notice — a platform admin removed this user's 2FA requirement (AH-087).
 *
 * The security-downgrade half of the exemption pair. Same posture as
 * {@see MfaResetByAdminMail}: individually-targeted, email-only, the reason
 * never quoted, the acting admin never named.
 *
 * The copy states the enrolled-and-exempt case explicitly, because the
 * exemption removes the REQUIREMENT and not the enrollment: a user who
 * already has an authenticator keeps it and is still challenged at sign-in.
 * Telling them otherwise would make a working 2FA prompt look like a bug.
 */
final class MfaExemptionGrantedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $recipientName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('auth.mfa_admin.exempted.subject'),
            tags: ['auth', 'mfa-admin-exempted'],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.identity.mfa-exemption-granted',
            with: [
                'recipientName' => $this->recipientName,
                'appName' => (string) config('app.name'),
            ],
        );
    }
}
