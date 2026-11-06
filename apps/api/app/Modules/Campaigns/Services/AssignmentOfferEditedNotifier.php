<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Mail\OfferEditedMail;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the creator their offer amount changed (AH-118): the in-app row (which
 * also drives the push, through `NotificationService::notify`) and the mail.
 *
 * Called by {@see AssignmentOfferEditor} AFTER its transaction has committed,
 * never inside it: `after_commit => false` means a queued mail is visible to a
 * worker immediately, so a rolled-back edit would have announced a change that
 * never happened.
 *
 * The mail leg is gated by `assignment_offer_edit_enabled` and nothing else —
 * the endpoint 404s while that flag is OFF, so every caller already holds it. It
 * still honours the recipient's own `email` preference for the type, through the
 * same checkpoint every other assignment mail uses.
 *
 * Silent for a creator who is erased, pending deletion or in an erasure claim
 * ({@see Creator::isUnavailableToAgencies()}); the
 * edit, its audit row and the history line are still written.
 *
 * Fail-quiet on a missing creator / user, like every sibling: a notification is
 * not worth failing an edit that has already committed.
 */
final class AssignmentOfferEditedNotifier
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function notify(CampaignAssignment $assignment, int $previousMinor, int $newMinor, ?User $actor): void
    {
        $creator = $assignment->creator;
        $campaign = $assignment->campaign;
        $recipient = $creator?->user;

        if ($creator === null || $campaign === null || ! $recipient instanceof User) {
            return;
        }

        // AH-104b-del (C4): the edit itself stands (it is history on an existing
        // assignment), but a creator scheduled for deletion, in an erasure claim
        // or erased is told nothing: no mail, no in-app row, no push.
        if ($creator->isUnavailableToAgencies()) {
            return;
        }

        $currency = (string) $assignment->agreed_fee_currency;

        if ($recipient->email !== '' && $this->notifications->isEmailEnabled($recipient, NotificationType::AssignmentOfferEdited)) {
            Mail::to($recipient->email)
                ->locale($recipient->mailLocale())
                ->queue(new OfferEditedMail(
                    creatorName: $creator->display_name ?? $recipient->name,
                    campaignName: $campaign->name,
                    assignmentUlid: $assignment->ulid,
                    previousAmount: self::format($previousMinor, $currency),
                    newAmount: self::format($newMinor, $currency),
                ));
        }

        $this->notifications->notify(
            recipient: $recipient,
            type: NotificationType::AssignmentOfferEdited,
            subject: $assignment,
            actor: $actor,
            data: [
                'campaign_name' => $campaign->name,
                'assignment_ulid' => $assignment->ulid,
                'previous_amount' => $previousMinor,
                'new_amount' => $newMinor,
                'currency' => $currency,
            ],
        );
    }

    /**
     * "100.00 EUR". Locale-neutral on purpose (no symbol, no grouping): the same
     * string reads the same in every one of the 24 locales.
     */
    public static function format(int $minorUnits, string $currency): string
    {
        return number_format($minorUnits / 100, 2, '.', '').' '.$currency;
    }
}
