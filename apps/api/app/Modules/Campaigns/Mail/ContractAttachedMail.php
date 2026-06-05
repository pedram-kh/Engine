<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Notify the creator that a per-campaign contract is ready to review (D-7).
 */
final class ContractAttachedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $creatorName,
        public readonly string $campaignName,
        public readonly string $assignmentUlid,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('campaigns.assignment_notifications.contract_attached.email.subject', ['campaign' => $this->campaignName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.campaigns.contract-attached',
            with: [
                'creatorName' => $this->creatorName,
                'campaignName' => $this->campaignName,
                'reviewUrl' => $this->buildAssignmentUrl(),
            ],
        );
    }

    private function buildAssignmentUrl(): string
    {
        $base = rtrim((string) config('app.frontend_main_url', 'http://127.0.0.1:5173'), '/');

        return $base.'/creator/assignments/'.$this->assignmentUlid;
    }
}
