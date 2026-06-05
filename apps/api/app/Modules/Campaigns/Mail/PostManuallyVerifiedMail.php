<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Mail;

use App\Modules\Campaigns\Listeners\SendAssignmentNotifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Notify the creator that their post was ACCEPTED after a failed
 * auto-verification (verification-resolution chunk, ACT1/D-8). The agency
 * manually overrode the failed check (`posted → manually_verified`), closing
 * the loop on a post that had failed. Dispatched by the
 * {@see SendAssignmentNotifications} listener on the
 * `assignment.manually_verified` transition. The internal override reason is
 * NOT surfaced to the creator (it lives in the audit trail).
 *
 * Queued + localized at queue time + the shared `catalyst` markdown theme.
 */
final class PostManuallyVerifiedMail extends Mailable implements ShouldQueue
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
            subject: __('campaigns.assignment_notifications.manually_verified.email.subject', ['campaign' => $this->campaignName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.campaigns.post-manually-verified',
            with: [
                'creatorName' => $this->creatorName,
                'campaignName' => $this->campaignName,
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
