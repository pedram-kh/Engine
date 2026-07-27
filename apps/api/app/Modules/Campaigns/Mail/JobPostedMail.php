<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Mail;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\JobPostedFanOutService;
use App\Modules\Creators\Mail\IncompleteCreatorNudgeMail;
use App\Modules\Identity\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "A new job is on your board" (AH-056, D6) — the mail half of the job-posted
 * dual emit.
 *
 * Subject + body are localized to the recipient's `preferred_language` via the
 * mailable `locale()` helper applied at QUEUE time in
 * {@see JobPostedFanOutService::queueMail()}, not at render time: the worker
 * that renders this has no request locale, so a mailable that resolved its own
 * locale would send every creator English. That is the
 * {@see IncompleteCreatorNudgeMail} pattern, and the
 * §5.3 test renders this mailable for real in a non-English locale rather than
 * asserting the queue call.
 *
 * `tags: ['campaigns', 'job-posted']` keeps it in the transactional stream and
 * out of any future marketing sends.
 *
 * ⚠ The body carries the campaign name, the agency name and a deep link, and
 * NOTHING about the brand. The brand subset (D3) is a decision about what a
 * creator sees on the BOARD, behind the visibility predicate; an email is not
 * behind that predicate — it lands in an inbox and can be forwarded — so the
 * brand's identity waits until the creator opens the job.
 */
final class JobPostedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $user,
        public Campaign $campaign,
        public string $actionUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('campaigns.job_posted.subject', [
                'agency' => $this->campaign->agency->name,
            ]),
            tags: ['campaigns', 'job-posted'],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.campaigns.job-posted',
            with: [
                'user' => $this->user,
                'campaignName' => $this->campaign->name,
                'agencyName' => $this->campaign->agency->name,
                'actionUrl' => $this->actionUrl,
                'appName' => config('app.name'),
            ],
        );
    }
}
