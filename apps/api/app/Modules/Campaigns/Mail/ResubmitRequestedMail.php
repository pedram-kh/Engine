<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Mail;

use App\Modules\Campaigns\Http\Controllers\CampaignAssignmentResolutionController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Notify the creator that the agency has asked them to resubmit their post
 * after a failed auto-verification (verification-resolution chunk, D-8). Two
 * modes:
 *   - `fresh`    (ACT2) — the assignment was sent back to `approved`; the
 *     creator submits a brand-new post URL via the existing posted-content flow.
 *   - `in_place` (ACT3) — the assignment stays `posted`; the creator edits the
 *     existing post URL in place (which re-arms verification).
 *
 * Sent DIRECTLY by {@see CampaignAssignmentResolutionController} (not via the
 * transition listener) so the free-text `$feedback` reaches the creator WITHOUT
 * being snapshotted into the audit metadata (the hand-written-audit discipline,
 * D-3).
 *
 * Queued + localized at queue time + the shared `catalyst` markdown theme.
 */
final class ResubmitRequestedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * @param  'fresh'|'in_place'  $mode
     */
    public function __construct(
        public readonly string $creatorName,
        public readonly string $campaignName,
        public readonly string $mode,
        public readonly ?string $feedback,
        public readonly string $assignmentUlid,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('campaigns.assignment_notifications.resubmit_requested.email.subject', ['campaign' => $this->campaignName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.campaigns.resubmit-requested',
            with: [
                'creatorName' => $this->creatorName,
                'campaignName' => $this->campaignName,
                'mode' => $this->mode,
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
