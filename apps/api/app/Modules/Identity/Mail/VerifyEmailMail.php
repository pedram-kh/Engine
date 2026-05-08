<?php

declare(strict_types=1);

namespace App\Modules\Identity\Mail;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\EmailVerificationService;
use App\Modules\Identity\Services\SignUpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Outgoing email containing the email-verification link.
 *
 * Subject and body are localized to the recipient's `preferred_language`
 * (en/pt/it). Localization is applied via Laravel's mailable `locale()`
 * helper inside {@see SignUpService} and
 * {@see EmailVerificationService}.
 *
 * The link points at the main SPA's verify-email page, with the signed
 * token in the query string. The SPA POSTs to
 * `/api/v1/auth/verify-email` to complete.
 *
 * Reference: docs/05-SECURITY-COMPLIANCE.md §6.5.
 */
final class VerifyEmailMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $user,
        public string $verifyUrl,
        public int $expiresInHours,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('auth.email_verification.subject', ['app' => config('app.name')]),
            tags: ['auth', 'email-verification'],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.identity.verify-email',
            with: [
                'user' => $this->user,
                'verifyUrl' => $this->verifyUrl,
                'expiresInHours' => $this->expiresInHours,
                'appName' => config('app.name'),
            ],
        );
    }
}
