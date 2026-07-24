<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Mail;

use App\Modules\Creators\Mail\CreatorApprovedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Admin direct-connect (Door 2) notification to a creator — AH-051 (D-7).
 *
 * Dispatched by the admin connections controller when a platform admin
 * DIRECTLY connects an agency to this creator (records an offline agreement).
 * The creator is notified IMMEDIATELY, naming the agency, with a "contact
 * support if unexpected" line — the offline-agreement consent safety net.
 *
 * Queued (ShouldQueue), localized via Mail::locale() at queue time to the
 * creator's preferred language, rendered through the shared `catalyst` markdown
 * theme (config/mail.php) — mirrors {@see ConnectionRequestMail} /
 * {@see CreatorApprovedMail}. Half of the D-7
 * dual-emit (in-app RelationAdminConnected is the other half).
 *
 * Real provider is deferred — config/mail.php default is `log`. Verified via
 * Mail::fake() (dispatch + content + locale), not a real inbox.
 */
final class AdminConnectedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $creatorDisplayName,
        public readonly string $agencyName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('creators.admin_connected.email.subject', ['agency' => $this->agencyName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.agencies.admin-connected',
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
