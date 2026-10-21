<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Events\AccountDeletionRequested;
use App\Modules\Identity\Features\AccountDeletionEnabled;
use App\Modules\Identity\Mail\AccountDeletionRequestedMail;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Laravel\Pennant\Feature;

/**
 * Sends the confirmation mail for a committed deletion request (A6).
 *
 * Runs on the same flag as the endpoint (Q8): with `account_deletion_enabled`
 * off nothing is sent, so switching the feature off also silences any request
 * still being processed. One mail per request: the service dispatches the event
 * only for a request that wrote the dates, never for a repeat.
 */
final class SendAccountDeletionRequestedMail
{
    public function __construct(private readonly MailFactory $mail) {}

    public function handle(AccountDeletionRequested $event): void
    {
        if (! Feature::active(AccountDeletionEnabled::NAME)) {
            return;
        }

        $user = $event->user;
        if ($user->email === '') {
            return;
        }

        $this->mail->mailer()
            ->to($user->email)
            ->locale($user->preferred_language ?: 'en')
            ->queue(new AccountDeletionRequestedMail(
                recipientName: $user->name,
                scheduledFor: $event->scheduledFor,
                timezone: $user->timezone ?: 'UTC',
            ));
    }
}
