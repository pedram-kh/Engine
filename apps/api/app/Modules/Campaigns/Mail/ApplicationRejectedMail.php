<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Mail;

use App\Modules\Campaigns\Enums\ApplicationRejectionCause;
use App\Modules\Campaigns\Services\CampaignApplicationNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "An update on your application" (AH-058, D4/D5) — the mail half of
 * `campaign_application.rejected`, to the creator.
 *
 * ONE mailable, TWO body variants, chosen by {@see ApplicationRejectionCause}:
 *   - `agency_rejected` — the agency answered no (D4);
 *   - `campaign_closed` — the campaign went completed/cancelled with the
 *     application still pending, so it was auto-rejected (D5).
 * The subject is SHARED. Splitting this into two mailables would double the
 * vocabulary, the templates and 24 locales of copy to express a difference of one
 * sentence, and the recipient's question is the same either way.
 *
 * The variant is selected in the blade off `body_{cause}`, the
 * `draft-reviewed.blade.php` precedent (`body_ . $outcome`), so the cause value
 * is part of the notification contract: it is the same string the in-app row
 * carries in `data.cause` and the SPA template may read.
 *
 * ⚠ No agency-supplied reason exists, by design (D4): none is collected, stored
 * or rendered. The copy is a kind generic "not selected this time", the audit row
 * plus its actor is the internal record, and the creator cannot re-apply
 * regardless (the retained terminal row occupies the unique pair), so a reason
 * would be an explanation with no action attached to it.
 *
 * Scalars only + queue-time locale, queued after the transaction commits — see
 * {@see ApplicationAcceptedMail} and {@see CampaignApplicationNotifier}.
 */
final class ApplicationRejectedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $creatorName,
        public string $campaignName,
        public ApplicationRejectionCause $cause,
        public string $actionUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('campaigns.campaign_application.rejected.subject', [
                'campaign' => $this->campaignName,
            ]),
            tags: ['campaigns', 'application-rejected'],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.campaigns.application-rejected',
            with: [
                'creatorName' => $this->creatorName,
                'campaignName' => $this->campaignName,
                // The blade appends this to `body_`, so the enum's value IS the
                // key suffix — one place decides the variant.
                //
                // ⚠ Named `causeKey`, not `cause`: a Mailable shares its PUBLIC
                // PROPERTIES with the view too, and `$cause` (the enum) would win
                // over this string, making the blade concatenate an object.
                'causeKey' => $this->cause->value,
                'actionUrl' => $this->actionUrl,
                'appName' => config('app.name'),
            ],
        );
    }
}
