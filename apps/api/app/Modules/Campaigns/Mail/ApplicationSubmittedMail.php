<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Mail;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\CampaignApplicationNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "A creator applied to your campaign" (AH-058, D6) — the mail half of the
 * `campaign_application.submitted` dual emit, to the agency's admins + managers.
 *
 * Scalars only, no models: the recipient set is a fan-out (every admin and
 * manager), so this is constructed once per recipient inside a queued path where
 * a serialized {@see Campaign} would be re-resolved
 * through the tenancy global scope in a worker with no tenant context. Passing
 * the two names and the deep link avoids the question entirely — the
 * {@see PostManuallyVerifiedMail} shape.
 *
 * Localized at QUEUE time to the recipient's `preferred_language` by
 * {@see CampaignApplicationNotifier}, not at render time: a worker has no request
 * locale, so a mailable resolving its own would send every recipient English.
 *
 * ⚠ The creator's application NOTE is deliberately absent from the body. It is
 * free text the creator wrote for the agency's review surface, and an email can
 * be forwarded outside it; the notice says who applied and links to the tab where
 * the note is read behind the agency's own authorization.
 */
final class ApplicationSubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $creatorName,
        public string $campaignName,
        public string $actionUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('campaigns.campaign_application.submitted.subject', [
                'campaign' => $this->campaignName,
            ]),
            tags: ['campaigns', 'application-submitted'],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.campaigns.application-submitted',
            with: [
                'recipientName' => $this->recipientName,
                'creatorName' => $this->creatorName,
                'campaignName' => $this->campaignName,
                'actionUrl' => $this->actionUrl,
                'appName' => config('app.name'),
            ],
        );
    }
}
