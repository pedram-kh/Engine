<?php

declare(strict_types=1);

namespace App\Modules\Identity\Mail;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\PasswordResetService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Outgoing email containing the password-reset link.
 *
 * Subject and body are localized to the recipient's
 * `preferred_language` (en/pt/it). Localization is applied via Laravel's
 * mailable `locale()` helper inside {@see PasswordResetService}.
 *
 * The link points at the main SPA's reset page, with token + email as
 * query string. The SPA POSTs to `/api/v1/auth/reset-password` to complete.
 *
 * Reference: docs/05-SECURITY-COMPLIANCE.md §6.6.
 */
final class ResetPasswordMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $user,
        public string $resetUrl,
        public int $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('auth.reset.subject', ['app' => config('app.name')]),
            tags: ['auth', 'password-reset'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.identity.reset-password',
            with: [
                'user' => $this->user,
                'resetUrl' => $this->resetUrl,
                'expiresInMinutes' => $this->expiresInMinutes,
                'appName' => config('app.name'),
            ],
        );
    }
}
