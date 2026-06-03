<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Mail;

use App\Modules\Agencies\Http\Controllers\AgencyConnectionRequestController;
use App\Modules\Creators\Mail\CreatorApprovedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Connection-request notification to a creator — Sprint 6.6b (D-9).
 *
 * Dispatched by {@see AgencyConnectionRequestController}
 * when an agency sends (or re-sends) a discovery connection request. Queued
 * (ShouldQueue), localized via Mail::locale() at queue time to the creator's
 * preferred language, and rendered through the shared `catalyst` markdown
 * theme (set globally in config/mail.php) — mirrors {@see CreatorApprovedMail}.
 *
 * This is the ONLY email in the lifecycle: agency-notified-on-accept/decline
 * is DEFERRED (see docs/tech-debt.md) — the agency observes the result via the
 * discovery annotation's status update. The CTA points at the creator
 * dashboard; the dedicated requests inbox is Sprint 6.6c.
 *
 * Real provider is deferred — config/mail.php default is `log`. Verified via
 * Mail::fake() (dispatch + content + locale), not a real inbox.
 */
final class ConnectionRequestMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $creatorDisplayName,
        public readonly string $agencyName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('creators.connection_request.email.subject', ['agency' => $this->agencyName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.agencies.connection-request',
            with: [
                'displayName' => $this->creatorDisplayName,
                'agencyName' => $this->agencyName,
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
