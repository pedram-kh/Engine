<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Mail;

use App\Modules\Campaigns\Services\CampaignApplicationNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your application was accepted — here is the offer" (AH-058, D6) — the mail
 * half of `campaign_application.accepted`, to the creator.
 *
 * ⚠ The wording matters and is a product constraint, not a style choice: an
 * accepted application is an INVITATION, not an engagement. The accept creates a
 * standard `invited` assignment, so the creator still reviews the offer and
 * accepts or declines it exactly as a cold invitee would (D2). The body says
 * "review the offer", never "you have the job" — a creator who read this as a
 * commitment would be misled about their own position.
 *
 * The deep link goes to the ASSIGNMENT, not back to the job page: the offer is
 * what needs an answer now, and the job page's own accepted-state notice (D7)
 * links to the same place for anyone who arrives there instead.
 *
 * Scalars only + queue-time locale — see {@see ApplicationSubmittedMail} for both
 * reasons. Queued by {@see CampaignApplicationNotifier} after the accept
 * transaction has COMMITTED: `config/queue.php` sets `after_commit => false`, so
 * a mail queued inside the transaction would be visible to a worker before the
 * commit and would survive a rollback — telling a creator they were accepted for
 * an assignment that does not exist.
 */
final class ApplicationAcceptedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $creatorName,
        public string $agencyName,
        public string $campaignName,
        public string $actionUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('campaigns.campaign_application.accepted.subject', [
                'campaign' => $this->campaignName,
            ]),
            tags: ['campaigns', 'application-accepted'],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.campaigns.application-accepted',
            with: [
                'creatorName' => $this->creatorName,
                'agencyName' => $this->agencyName,
                'campaignName' => $this->campaignName,
                'actionUrl' => $this->actionUrl,
                'appName' => config('app.name'),
            ],
        );
    }
}
