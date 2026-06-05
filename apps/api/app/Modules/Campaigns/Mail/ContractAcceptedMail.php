<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Notify the agency that a creator accepted the per-campaign contract (D-7).
 */
final class ContractAcceptedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $creatorName,
        public readonly string $campaignName,
        public readonly string $campaignUlid,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('campaigns.assignment_notifications.contract_accepted.email.subject', ['creator' => $this->creatorName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.campaigns.contract-accepted',
            with: [
                'recipientName' => $this->recipientName,
                'creatorName' => $this->creatorName,
                'campaignName' => $this->campaignName,
                'campaignUrl' => $this->buildCampaignUrl(),
            ],
        );
    }

    private function buildCampaignUrl(): string
    {
        $base = rtrim((string) config('app.frontend_main_url', 'http://127.0.0.1:5173'), '/');

        return $base.'/campaigns/'.$this->campaignUlid;
    }
}
