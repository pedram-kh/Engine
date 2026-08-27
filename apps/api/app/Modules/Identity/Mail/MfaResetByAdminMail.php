<?php

declare(strict_types=1);

namespace App\Modules\Identity\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Security notice — a platform admin reset this user's 2FA enrollment (AH-087).
 *
 * The individually-targeted posture from AH-051: an admin action taken on ONE
 * named person's account is one the person must see, unlike the silent
 * platform-infrastructure actions (agency suspend). Email-only — there is no
 * in-app half and no NotificationType, the deliberately email-only shape the
 * blacklist notice established.
 *
 * Two disciplines carried from the AH-051 eyes-on pass:
 *   - the admin's REASON never appears in the mail (it is the internal paper
 *     trail, in the audit row, not user-facing copy);
 *   - the acting admin is never named.
 *
 * The "if you didn't request this" line matters more here than in most
 * notices: the same operation a support request produces is also what an
 * account takeover would look like, so the user is the last line of defence.
 *
 * Queued (ShouldQueue), localized via Mail::locale() at queue time to the
 * recipient's preferred language, rendered through the shared `catalyst`
 * markdown theme.
 */
final class MfaResetByAdminMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $recipientName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('auth.mfa_admin.reset.subject'),
            tags: ['auth', 'mfa-admin-reset'],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.identity.mfa-reset-by-admin',
            with: [
                'recipientName' => $this->recipientName,
                'appName' => (string) config('app.name'),
                'signInUrl' => $this->buildSignInUrl(),
            ],
        );
    }

    /**
     * The agency-branded door (AH-086b) — an agency member who has just lost
     * their 2FA setup needs the sign-in page, which is where re-enrollment
     * starts.
     */
    private function buildSignInUrl(): string
    {
        $base = rtrim((string) config('app.frontend_main_url', 'http://127.0.0.1:5173'), '/');

        return $base.'/agency/sign-in';
    }
}
