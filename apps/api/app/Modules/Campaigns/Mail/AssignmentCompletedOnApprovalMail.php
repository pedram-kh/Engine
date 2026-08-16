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
 * Notify the creator that their assignment is FINISHED because the agency
 * approved their draft (AH-069, D5). The campaign hands off at approval — the
 * agency publishes the deliverable itself — so there is no post to make and no
 * verification to wait for.
 *
 * Dispatched by the {@see SendAssignmentNotifications} listener on the
 * `assignment.completed_on_approval` transition. It is the ONLY email that click
 * sends: the draft-approved mail is suppressed for this campaign type (Q3),
 * because this one carries the same news plus the part that matters.
 *
 * ⚠ The copy must never say "posted" or "verified". Nothing was published
 * through the platform and no verification ran; claiming either would be a lie
 * the creator could act on. The same constraint governs the in-app template and
 * the creator-facing banner.
 *
 * No `round` parameter, deliberately: unlike the AH-068 draft mailables, this
 * message is not about a round of review — it is about the assignment being
 * over. Naming a round here would invite the reader to expect another one.
 *
 * Queued + localized at queue time + the shared `catalyst` markdown theme.
 */
final class AssignmentCompletedOnApprovalMail extends Mailable implements ShouldQueue
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
            subject: __('campaigns.assignment_notifications.completed_on_approval.email.subject', ['campaign' => $this->campaignName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.campaigns.assignment-completed-on-approval',
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
