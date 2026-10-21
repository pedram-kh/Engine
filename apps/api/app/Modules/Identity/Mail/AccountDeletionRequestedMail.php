<?php

declare(strict_types=1);

namespace App\Modules\Identity\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/**
 * Confirmation that a creator's account is scheduled for deletion (AH-104b-del A6).
 *
 * Sent once, to the address on the account, after the request commits. It says
 * what was asked, the date it becomes permanent, how to keep the account (sign
 * in and restore it), and what to do if the person did not ask: the last line
 * of defence against someone who got into the account.
 *
 * Deliberately NOT in this mail:
 *   - a cancel or restore LINK. A link in an inbox is a credential in an
 *     inbox; restoring needs the password (and second factor), so the mail
 *     only says to sign in. Nothing here can be clicked to change the account.
 *   - anything that identifies what the account held.
 *
 * The date is rendered at send time in the recipient's locale and timezone, so
 * the queued payload carries an instant, not a string. The support address
 * comes from `config('app.support_email')`, the one place it lives.
 *
 * Queued, localised via Mail::locale() to the recipient's `preferred_language`.
 */
final class AccountDeletionRequestedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $recipientName,
        public readonly CarbonInterface $scheduledFor,
        public readonly string $timezone = 'UTC',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('auth.deletion_mail.subject', ['app' => (string) config('app.name')]),
            tags: ['auth', 'account-deletion-requested'],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.identity.account-deletion-requested',
            with: [
                'recipientName' => $this->recipientName,
                'appName' => (string) config('app.name'),
                'date' => $this->formattedDate(),
                'support' => (string) config('app.support_email'),
            ],
        );
    }

    private function formattedDate(): string
    {
        $zone = in_array($this->timezone, timezone_identifiers_list(), true) ? $this->timezone : 'UTC';

        $date = Carbon::instance($this->scheduledFor)->setTimezone($zone);
        $date->locale(app()->getLocale());

        return $date->isoFormat('LL');
    }
}
