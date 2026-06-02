<?php

declare(strict_types=1);

namespace App\Modules\Creators\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Rejection notification to a creator — Sprint 4 Chunk 3 (D-c3-11).
 *
 * Dispatched by AdminCreatorController::reject(). Queued (ShouldQueue),
 * localized via Mail::locale() at queue time, rendered through the shared
 * `catalyst` markdown theme. The mail carries the rejection reason so the
 * creator knows what to address before resubmitting (the in-app rejected
 * banner surfaces the same reason — D-c3-1).
 *
 * Real provider is deferred (open-question d); verified via Mail::fake().
 */
final class CreatorRejectedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $creatorDisplayName,
        public readonly string $rejectionReason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('creators.rejected.email.subject'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.creators.rejected',
            with: [
                'displayName' => $this->creatorDisplayName,
                'rejectionReason' => $this->rejectionReason,
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
