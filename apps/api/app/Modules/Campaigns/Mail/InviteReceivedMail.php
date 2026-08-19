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
 * Notify the creator that a campaign offer is waiting for them (AH-083, ①).
 * Dispatched by the {@see SendAssignmentNotifications} listener on all THREE
 * paths that land an assignment on `invited` (kickoff Q4 — treated
 * identically, since the creator's actionable fact is the same on all three):
 *
 *   - `assignment.invited`    — the fresh, first-time invite
 *   - `assignment.re_invited` — the AH-035 re-offer after a decline, AND the
 *     agency's re-offer answering the creator's own counter (D-7). Both
 *     share this one `AuditAction`; kickoff Q4 ratified including both
 *     rather than threading a context flag through the state machine purely
 *     to exclude one.
 *
 * `$outcome` (kickoff Q5) is the light copy discriminator: `fresh` reads
 * "you've been invited"; `re_offer` reads "an updated offer is waiting" and
 * covers BOTH re-invite paths identically — from the creator's seat, a
 * re-offer after their decline and a re-offer after their counter are the
 * same experience (an updated offer, not a first invite).
 *
 * Queued + localized at queue time + the shared `catalyst` markdown theme,
 * the `DraftReviewedMail` shared-family shape (one class, one Blade view, an
 * outcome discriminator picking subject/body variant).
 */
final class InviteReceivedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * @param  'fresh'|'re_offer'  $outcome
     */
    public function __construct(
        public readonly string $creatorName,
        public readonly string $campaignName,
        public readonly string $outcome,
        public readonly string $assignmentUlid,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: (string) __('campaigns.assignment_notifications.invite_received.email.subject_'.$this->outcome, ['campaign' => $this->campaignName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.campaigns.invite-received',
            with: [
                'creatorName' => $this->creatorName,
                'campaignName' => $this->campaignName,
                'outcome' => $this->outcome,
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
