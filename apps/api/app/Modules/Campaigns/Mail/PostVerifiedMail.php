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
 * Notify the creator that their post PASSED automatic verification (AH-101 ⑦,
 * `posted → live_verified`). The success-branch sibling of
 * {@see PostManuallyVerifiedMail} (the human override) and the counterpart of
 * {@see PostVerificationFailedMail} (the agency-facing failure).
 *
 * Dispatched by the {@see SendAssignmentNotifications} listener on the
 * `assignment.live_verified` transition, behind `post_verified_mail_enabled`
 * (default OFF — it must not arm over the mock verification driver).
 *
 * ⚠ The copy claims exactly what happened and nothing more: the post was found at
 * the submitted link. It makes no promise about payment or approval — those are
 * separate transitions with their own owners.
 *
 * Queued + localized at queue time + the shared `catalyst` markdown theme.
 */
final class PostVerifiedMail extends Mailable implements ShouldQueue
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
            subject: __('campaigns.assignment_notifications.post_verified.email.subject', ['campaign' => $this->campaignName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.campaigns.post-verified',
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
