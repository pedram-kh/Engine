<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationPreference;
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
    /**
     * Emit an in-app notification to a recipient if their `in_app` preference
     * for this type is enabled. Returns the written row, or null when in-app
     * is disabled for the (recipient, type) pair.
     *
     * @param  array<string, mixed>  $data  Render params only (e.g.
     *                                      {campaign_name, creator_name}) —
     *                                      NEVER localized text. The body
     *                                      renders client-side (Ch3) from
     *                                      `type` + this payload.
     */
    public function notify(
        User $recipient,
        NotificationType $type,
        ?Model $subject = null,
        ?User $actor = null,
        array $data = [],
    ): ?Notification {
        if (! $this->isChannelEnabled($recipient, $type, NotificationChannel::InApp)) {
            return null;
        }

        return Notification::query()->create([
            'recipient_user_id' => $recipient->getKey(),
            'actor_user_id' => $actor?->getKey(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'type' => $type,
            'data' => $data,
        ]);
    }

    /**
     * Apply a single preference toggle, SPARSELY (S11.0 Chunk 3b, D-1).
     *
     * The table holds ONLY divergences from {@see NotificationChannel::defaultEnabled()}
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

        if ($isEnabled === $channel->defaultEnabled()) {
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
     * Resolve whether a channel is enabled for a (user, type) pair.
     *
     * Preserve-current default (D-7): when no preference row exists, fall back
     * to the channel default — `in_app`/`email` ON, `digest` OFF.
     */
    public function isChannelEnabled(User $recipient, NotificationType $type, NotificationChannel $channel): bool
    {
        $preference = NotificationPreference::query()
            ->where('user_id', $recipient->getKey())
            ->where('type', $type->value)
            ->where('channel', $channel->value)
            ->first();

        if ($preference === null) {
            return $channel->defaultEnabled();
        }

        return $preference->is_enabled;
    }
}
