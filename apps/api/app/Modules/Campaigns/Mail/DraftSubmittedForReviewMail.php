<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Mail;

use App\Modules\Agencies\Mail\ConnectionRequestMail;
use App\Modules\Campaigns\Listeners\SendAssignmentNotifications;
use App\Modules\Campaigns\Mail\Concerns\CarriesDraftRound;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Notify the agency that a creator submitted a draft for review (Sprint 9
 * Chunk 2, D-14). Dispatched by the {@see SendAssignmentNotifications}
 * listener on `assignment.draft_submitted`. Recipient is the assignment's
 * inviting agency member (`invited_by_user_id`) — see docs/tech-debt.md (no
 * agency-wide shared inbox yet).
 *
 * Queued + localized at queue time + rendered through the shared `catalyst`
 * markdown theme — mirrors {@see ConnectionRequestMail}.
 */
final class DraftSubmittedForReviewMail extends Mailable implements ShouldQueue
{
    use CarriesDraftRound;
    use Queueable;

    /**
     * @param  int|null  $round  The submitted draft's `version` (AH-068, D4) —
     *                           what distinguishes a resubmission from a first
     *                           submission, since both fire the same verb.
     */
    public function __construct(
        public readonly string $recipientName,
        public readonly string $creatorName,
        public readonly string $campaignName,
        public readonly string $campaignUlid,
        public readonly ?int $round = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->withRoundInSubject(
                (string) __('campaigns.assignment_notifications.draft_submitted.email.subject', ['creator' => $this->creatorName]),
                $this->round,
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.campaigns.draft-submitted',
            with: [
                'recipientName' => $this->recipientName,
                'creatorName' => $this->creatorName,
                'campaignName' => $this->campaignName,
                'roundLabel' => $this->roundLabel($this->round),
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
