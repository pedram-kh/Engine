<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The creator hears that the agency changed the offer amount (AH-118 item 2).
 * No confirmation step: this is information, the creator's reply path is the
 * assignment thread (which carries the same change as a history line).
 *
 * Amounts arrive pre-formatted ("100.00 EUR"), so the mail and the in-app row
 * cannot disagree about rounding. Queued, localised at queue time, `catalyst`
 * markdown theme.
 */
final class OfferEditedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $creatorName,
        public readonly string $campaignName,
        public readonly string $assignmentUlid,
        public readonly string $previousAmount,
        public readonly string $newAmount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('campaigns.assignment_notifications.offer_edited.email.subject', ['campaign' => $this->campaignName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.campaigns.offer-edited',
            with: [
                'creatorName' => $this->creatorName,
                'campaignName' => $this->campaignName,
                'previousAmount' => $this->previousAmount,
                'newAmount' => $this->newAmount,
                'assignmentUrl' => $this->buildAssignmentUrl(),
            ],
        );
    }

    private function buildAssignmentUrl(): string
    {
        $base = rtrim((string) config('app.frontend_main_url', 'http://127.0.0.1:5173'), '/');

        return $base.'/creator/assignments/'.$this->assignmentUlid;
    }
}
