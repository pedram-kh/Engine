<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Creators\Features\MissingCreatorMailsEnabled;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Mail\NewMessageMail;
use App\Modules\Messaging\Models\MessageEmailDebounce;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;

/**
 * AH-083 (⑧) — the ONE checkpoint for the debounced message email (D3/D4),
 * the `CampaignApplicationNotifier::queue()` sibling for messaging. Called
 * from EXACTLY TWO places — the "agency→creator" tail of
 * {@see SendMessageNotifications::dispatch()} and the identical tail of
 * {@see RelationshipMessageNotifications::dispatch()} — never their
 * creator→agency fan-out branches. D4 ("creators only") is therefore a
 * property of WHERE this is called, not a role check inside this class.
 *
 * Rule: email on first unread; re-armed once 30 minutes have passed since the
 * thread's last EMAILED notification to that recipient; never more than one
 * email per (thread, recipient) per 30 minutes.
 *
 * ── The flag gates the WHOLE method, not just the mail queue call ──────────
 *
 * Unlike `CampaignApplicationNotifier` (where the in-app row writes
 * unconditionally and only the mail leg is flag-gated), this service's ENTIRE
 * body — including the debounce table read/write — sits behind
 * {@see MissingCreatorMailsEnabled}. While the flag is off, the debounce table
 * is NEVER touched: a total no-op, so the first message after an operator
 * later flips the flag ON correctly reads as "first unread" and emails
 * immediately, rather than the table having been silently ticking in the
 * background. The in-app row this method has no opinion about is written
 * unconditionally by the CALLER, above this method, exactly as before.
 *
 * ── The atomic upsert (§5.6) ─────────────────────────────────────────────
 *
 * Two messages landing on the same thread within milliseconds must not both
 * pass the 30-minute check. `firstOrCreate()` on Laravel 11 already resolves
 * the "brand new row" race atomically (it retries as a lookup on a unique
 * constraint violation — {@see Builder::createOrFirst()}).
 * The "existing row, past its window" race is closed by a single conditional
 * `UPDATE ... WHERE last_emailed_at <= :threshold` and reading its affected
 * row count: exactly one concurrent caller can ever see `1` back, because the
 * database applies row-level locking to the UPDATE itself — no explicit
 * transaction or `lockForUpdate()` needed, and the technique is identical on
 * Postgres, MySQL, and SQLite.
 */
final class DebouncedMessageMailer
{
    private const int DEBOUNCE_MINUTES = 30;

    public function __construct(private readonly NotificationService $preferences) {}

    /**
     * @return bool whether the mail was queued, so a caller wanting to log
     *              the outcome does not have to re-read the flag itself.
     */
    public function maybeSend(Model $thread, User $recipient, NewMessageMail $mailable): bool
    {
        if (! Feature::active(MissingCreatorMailsEnabled::NAME)) {
            $this->logEmission($thread, $recipient, $mailable->context, 'flag_suppressed');

            return false;
        }

        if ($recipient->email === '') {
            $this->logEmission($thread, $recipient, $mailable->context, 'no_email');

            return false;
        }

        // AH-101 (A1) — the recipient's `email` preference for the type this mail
        // announces. AFTER the flag (the kill switch still kills) and BEFORE the
        // debounce row: a suppressed send must never touch the debounce table,
        // or a later opt-back-in would be silently debounced out of its first
        // unread. Absence means ON, so with no row this is a pass-through.
        if (! $this->preferences->isEmailEnabled($recipient, $this->typeFor($mailable))) {
            $this->logEmission($thread, $recipient, $mailable->context, 'preference_suppressed');

            return false;
        }

        if (! $this->shouldEmail($thread, $recipient)) {
            $this->logEmission($thread, $recipient, $mailable->context, 'debounced');

            return false;
        }

        Mail::to($recipient->email)
            // Queue-time locale, not render-time: the worker has no request
            // locale, so a mailable resolving its own would send everyone English.
            ->locale($recipient->preferred_language ?: 'en')
            ->queue($mailable);

        $this->logEmission($thread, $recipient, $mailable->context, 'sent');

        return true;
    }

    /**
     * The creator-facing notification type this mail announces — the SAME type
     * the caller's immediate in-app row was written under, derived from the
     * mailable's own `context` so no caller can pass a mismatched pair.
     */
    private function typeFor(NewMessageMail $mailable): NotificationType
    {
        return $mailable->context === 'relationship'
            ? NotificationType::MessageRelationshipReceivedByCreator
            : NotificationType::MessageReceivedByCreator;
    }

    /**
     * The debounce decision, and — when it resolves to "yes, email" — the
     * SAME atomic operation that reserves the window, so a decision can never
     * be made twice for one re-arm.
     */
    private function shouldEmail(Model $thread, User $recipient): bool
    {
        $threshold = now()->subMinutes(self::DEBOUNCE_MINUTES);

        $row = MessageEmailDebounce::query()->firstOrCreate(
            [
                'thread_type' => $thread->getMorphClass(),
                'thread_id' => $thread->getKey(),
                'recipient_user_id' => $recipient->getKey(),
            ],
            ['last_emailed_at' => now()],
        );

        if ($row->wasRecentlyCreated) {
            return true;
        }

        if ($row->last_emailed_at->greaterThan($threshold)) {
            return false;
        }

        $rearmed = MessageEmailDebounce::query()
            ->whereKey($row->getKey())
            ->where('last_emailed_at', '<=', $threshold)
            ->update(['last_emailed_at' => now()]);

        return $rearmed === 1;
    }

    /**
     * One structured line per evaluation (the `CampaignApplicationNotifier`
     * `logEmission()` precedent, AH-059 D2) — a debounce silencing a message
     * must be as legible in the logs as a flag silencing one, or the two are
     * indistinguishable from a broken mail path after the fact.
     */
    private function logEmission(Model $thread, User $recipient, string $context, string $outcome): void
    {
        Log::info('messaging: debounced email evaluated', [
            'thread_type' => $thread->getMorphClass(),
            'thread_id' => $thread->getKey(),
            'recipient_user_id' => $recipient->getKey(),
            'context' => $context,
            'outcome' => $outcome,
        ]);
    }
}
