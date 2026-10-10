<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Push\PushDispatcher;
use Illuminate\Database\Eloquent\Model;

/**
 * The single emit seam for the notification subsystem (S11.0 Chunk 1, D-6).
 *
 * {@see self::notify()} reads the recipient's preferences and writes a
 * `notifications` row when in-app is enabled for that type. IN-APP ONLY this
 * chunk: it does NOT touch email. The existing Mailables stay exactly where
 * they are — Ch2 calls this same service ALONGSIDE each existing Mail::queue,
 * never instead of it. Email-channel ownership is deliberately NOT refactored
 * into this service (a bigger change, not this sprint).
 *
 * Preference resolution is computed (D-7): a MISSING row resolves to the
 * channel default (`in_app`/`email` ON, `digest` OFF). No per-user row is
 * seeded, so a missing row can never silently disable an existing email.
 */
final class NotificationService
{
    public function __construct(private readonly PushDispatcher $push) {}

    /**
     * Emit a notification to a recipient: write the in-app row when their `in_app`
     * preference for this type is enabled, and (AH-102) hand the event to the push
     * leg. Returns the written row, or null when in-app is disabled for the
     * (recipient, type) pair.
     *
     * PUSH IS INDEPENDENT OF IN-APP. A recipient who muted in-app for a type still
     * gets its push unless they muted push, so there is no early return before the
     * push leg (AH-102 Q2/§5.4). The leg runs on BOTH paths and can never throw
     * ({@see PushDispatcher::dispatch()}), so it cannot affect the in-app row or the
     * caller's transaction. It is off by default behind `push_notifications_enabled`.
     *
     * @param  array<string, mixed>  $data  Render params only (e.g.
     *                                      {campaign_name, creator_name}) —
     *                                      NEVER localized text. The body
     *                                      renders client-side (Ch3) from
     *                                      `type` + this payload.
     * @param  bool  $suppressPush  Skip ONLY the push leg (the in-app row is still
     *                              written). Mirrors AH-069 `suppressEmail`: set on
     *                              a draft approval that also completes the
     *                              assignment, whose completion push carries the
     *                              same news.
     */
    public function notify(
        User $recipient,
        NotificationType $type,
        ?Model $subject = null,
        ?User $actor = null,
        array $data = [],
        bool $suppressPush = false,
    ): ?Notification {
        $notification = $this->isChannelEnabled($recipient, $type, NotificationChannel::InApp)
            ? Notification::query()->create([
                'recipient_user_id' => $recipient->getKey(),
                'actor_user_id' => $actor?->getKey(),
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'type' => $type,
                'data' => $data,
            ])
            : null;

        $this->push->dispatch($recipient, $type, $data, $notification?->ulid, $suppressPush);

        return $notification;
    }

    /**
     * Apply a single preference toggle, SPARSELY (S11.0 Chunk 3b, D-1).
     *
     * The table holds ONLY divergences from {@see NotificationChannel::defaultEnabledFor()}
     * so the "missing row → default" read in {@see self::isChannelEnabled()} stays
     * the single source of truth:
     *   - toggle DIVERGES from the default → updateOrCreate the row;
     *   - toggle RETURNS to the default → DELETE the row.
     *
     * The delete (not a stored `is_enabled = default`) is the contract: a user
     * back at the default holds no row, so a future default flip (or the Ch2
     * retrofit) can never be silently overridden by a stale stored value.
     *
     * The (user_id, type, channel) unique constraint backs updateOrCreate
     * against a double-write race — a concurrent second write collides on the
     * unique and updates rather than duplicating.
     */
    public function setPreference(User $user, NotificationType $type, NotificationChannel $channel, bool $isEnabled): void
    {
        $query = NotificationPreference::query()
            ->where('user_id', $user->getKey())
            ->where('type', $type->value)
            ->where('channel', $channel->value);

        if ($isEnabled === $channel->defaultEnabledFor($type)) {
            $query->delete();

            return;
        }

        NotificationPreference::query()->updateOrCreate(
            [
                'user_id' => $user->getKey(),
                'type' => $type->value,
                'channel' => $channel->value,
            ],
            ['is_enabled' => $isEnabled],
        );
    }

    /**
     * AH-101 (A1) — the ONE email-preference guard. May this recipient be mailed
     * this notification type?
     *
     * Keyed on the RECIPIENT user and the {@see NotificationType} — never on the
     * mailable — so the exemption is structural: a mail with no NotificationType
     * (security / transactional / non-user, and the always-on account-lifecycle
     * mails) has no key to read and therefore cannot call this. The call sites
     * are pinned by `EmailPreferenceGuardArchitectureTest`.
     *
     * Absence means ON ({@see NotificationChannel::defaultEnabledFor()}), so with no
     * `email` row every guarded site sends exactly what it sent before this
     * existed. A stored `email=false` row is an expressed opt-out however it
     * arrived (Q1: honoured, never cleared). The guard sits INSIDE each site's
     * Pennant kill-switch, never instead of it.
     */
    public function isEmailEnabled(User $recipient, NotificationType $type): bool
    {
        return $this->isChannelEnabled($recipient, $type, NotificationChannel::Email);
    }

    /**
     * Resolve whether a channel is enabled for a (user, type) pair.
     *
     * Preserve-current default (D-7): when no preference row exists, fall back
     * to the (type-aware, AH-102) default — `in_app`/`email` ON, `digest` OFF,
     * unless the type carries an override.
     */
    public function isChannelEnabled(User $recipient, NotificationType $type, NotificationChannel $channel): bool
    {
        $preference = NotificationPreference::query()
            ->where('user_id', $recipient->getKey())
            ->where('type', $type->value)
            ->where('channel', $channel->value)
            ->first();

        if ($preference === null) {
            return $channel->defaultEnabledFor($type);
        }

        return $preference->is_enabled;
    }
}
