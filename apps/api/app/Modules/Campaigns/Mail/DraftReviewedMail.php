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
 * Notify the creator that their draft was reviewed (Sprint 9 Chunk 2, D-14) —
 * approved / revision_requested / rejected. Dispatched by the
 * {@see SendAssignmentNotifications} listener
 * on the corresponding review transition. The reviewer feedback (revision /
 * reject reason) is included verbatim in the body when present.
 *
 * Queued + localized at queue time + the shared `catalyst` markdown theme.
 */
final class DraftReviewedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * @param  'approved'|'revision_requested'|'rejected'  $outcome
     */
    public function __construct(
        public readonly string $creatorName,
        public readonly string $campaignName,
        public readonly string $outcome,
        public readonly ?string $feedback,
        public readonly string $assignmentUlid,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('campaigns.assignment_notifications.reviewed.email.subject_'.$this->outcome, ['campaign' => $this->campaignName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.campaigns.draft-reviewed',
            with: [
                'creatorName' => $this->creatorName,
                'campaignName' => $this->campaignName,
                'outcome' => $this->outcome,
                'feedback' => $this->feedback,
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
