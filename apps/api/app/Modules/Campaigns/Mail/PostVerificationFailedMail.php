<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Mail;

use App\Modules\Campaigns\Jobs\VerifyPostedContentJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Notify the agency that automatic verification of a creator's post failed
 * (Sprint 9 Chunk 2, D-13/D-14) — `not_found` or `mismatch`. The assignment
 * stays `posted` (no machine transition); this email prompts the agency to
 * review the submitted link. Dispatched directly by {@see VerifyPostedContentJob}
 * on a failed outcome. Recipient is the inviting agency member.
 *
 * Queued + localized at queue time + the shared `catalyst` markdown theme.
 */
final class PostVerificationFailedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * @param  'not_found'|'mismatch'  $outcome
     */
    public function __construct(
        public readonly string $recipientName,
        public readonly string $creatorName,
        public readonly string $campaignName,
        public readonly string $outcome,
        public readonly string $campaignUlid,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('campaigns.assignment_notifications.verification_failed.email.subject', ['campaign' => $this->campaignName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.campaigns.verification-failed',
            with: [
                'recipientName' => $this->recipientName,
                'creatorName' => $this->creatorName,
                'campaignName' => $this->campaignName,
                'outcome' => $this->outcome,
                'reviewUrl' => $this->buildCampaignUrl(),
            ],
        );
    }

    private function buildCampaignUrl(): string
    {
        $base = rtrim((string) config('app.frontend_main_url', 'http://127.0.0.1:5173'), '/');

        return $base.'/campaigns/'.$this->campaignUlid;
    }
}
