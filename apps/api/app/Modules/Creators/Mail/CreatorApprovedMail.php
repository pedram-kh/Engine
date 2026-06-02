<?php

declare(strict_types=1);

namespace App\Modules\Creators\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Approval notification to a creator — Sprint 4 Chunk 3 (D-c3-11).
 *
 * Dispatched by AdminCreatorController::approve(). Queued (ShouldQueue),
 * localized via Mail::locale() at queue time to the creator's preferred
 * language, and rendered through the shared `catalyst` markdown theme
 * (set globally in config/mail.php). The optional welcome_message the
 * admin stamped on approval is surfaced when present.
 *
 * Real provider is deferred (open-question d) — config/mail.php default
 * is `log`. Verified via Mail::fake() (dispatch + content + locale), not
 * a real inbox.
 */
final class CreatorApprovedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $creatorDisplayName,
        public readonly ?string $welcomeMessage = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('creators.approved.email.subject'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.creators.approved',
            with: [
                'displayName' => $this->creatorDisplayName,
                'welcomeMessage' => $this->welcomeMessage,
                'dashboardUrl' => $this->buildDashboardUrl(),
            ],
        );
    }

    private function buildDashboardUrl(): string
    {
        $base = rtrim((string) config('app.frontend_main_url', 'http://127.0.0.1:5173'), '/');

        return $base.'/creator/dashboard';
    }
}
