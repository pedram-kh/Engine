<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Mail;

use App\Modules\Agencies\Http\Controllers\CreatorBlacklistController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Blacklist notification to a creator — Sprint 7 (D-4).
 *
 * Dispatched by {@see CreatorBlacklistController} on a blacklist write ONLY
 * when the agency's `blacklist_notification_policy` setting is on. Queued
 * (ShouldQueue), localized to the creator's preferred language at queue time,
 * rendered through the shared `catalyst` markdown theme — mirrors
 * {@see ConnectionRequestMail}.
 *
 * GENERIC BY DESIGN: it carries NO reason, NO scope, NO hard/soft type. The
 * blacklist_reason is free-text + GDPR-sensitive (the same class as
 * internal_notes — redacted from the audit log, withheld from every read
 * resource), so it never leaves the agency boundary, the notification email
 * included. The creator is informed their status changed; they take it up with
 * the agency directly.
 *
 * Real provider is deferred — config/mail.php default is `log`. Verified via
 * Mail::fake() (dispatch + content + locale), not a real inbox.
 */
final class CreatorBlacklistedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $creatorDisplayName,
        public readonly string $agencyName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('creators.blacklisted.email.subject', ['agency' => $this->agencyName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.agencies.creator-blacklisted',
            with: [
                'displayName' => $this->creatorDisplayName,
                'agencyName' => $this->agencyName,
            ],
        );
    }
}
