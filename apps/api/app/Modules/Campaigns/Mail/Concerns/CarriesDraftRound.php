<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Mail\Concerns;

/**
 * The draft-round clause shared by the review-cycle mails (AH-068, D4).
 *
 * Both mails in the cycle name the round the same way, so the convention lives
 * here rather than twice: `Draft :n` as its own label, and `:subject (:round)` as
 * the subject wrapper — a wrapper key rather than PHP concatenation so a locale
 * can decide where the round goes and what encloses it.
 *
 * The round is OPTIONAL throughout. The listener omits it when the transition
 * carried no version (see `SendAssignmentNotifications::roundFromContext()`), and
 * a mail with no round renders exactly the subject and body it rendered before
 * this chunk — no empty parentheses, no dangling label.
 */
trait CarriesDraftRound
{
    /** The round's own label, or null when this mail has no round. */
    protected function roundLabel(?int $round): ?string
    {
        if ($round === null) {
            return null;
        }

        return (string) __('campaigns.assignment_notifications.round', ['n' => $round]);
    }

    /** The subject with the round appended, or the subject untouched. */
    protected function withRoundInSubject(string $subject, ?int $round): string
    {
        $label = $this->roundLabel($round);

        if ($label === null) {
            return $subject;
        }

        return (string) __('campaigns.assignment_notifications.round_subject', [
            'subject' => $subject,
            'round' => $label,
        ]);
    }
}
