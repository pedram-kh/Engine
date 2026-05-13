<?php

declare(strict_types=1);

namespace App\Modules\Creators\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Magic-link invitation mail to a prospective creator.
 *
 * The token is unhashed (the recipient will paste it back via the
 * accept link). Only the SHA-256 hash is persisted in the database.
 *
 * Localised templates live under resources/views/mail/creators/
 * invitations/{en,pt,it}/. The locale is passed via
 * `Mail::locale()` at queue time.
 */
final class ProspectCreatorInviteMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $agencyName,
        public readonly string $token,
        public readonly string $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('creators.invitations.email.subject', ['agency' => $this->agencyName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.creators.invitations.invite',
            with: [
                'agencyName' => $this->agencyName,
                'acceptUrl' => $this->buildAcceptUrl(),
                'expiresAt' => $this->expiresAt,
            ],
        );
    }

    private function buildAcceptUrl(): string
    {
        $base = rtrim((string) config('app.frontend_main_url', 'http://127.0.0.1:5173'), '/');

        return $base.'/auth/accept-invite?token='.urlencode($this->token);
    }
}
