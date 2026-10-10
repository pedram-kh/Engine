<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push;

use App\Modules\Creators\Features\PushNotificationsEnabled;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Jobs\SendPushNotificationJob;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;
use Throwable;

/**
 * AH-102 — the push leg of {@see NotificationService::notify()}.
 *
 * Decides WHETHER a push goes out and fans it into one queued job per live
 * device token; it never talks to a provider (that is {@see SendPushNotificationJob}
 * → {@see PushSender}).
 *
 * THE CONTRACT WITH THE CALLER: `dispatch()` never throws. The in-app notification
 * (and, behind it, the surrounding state-machine transaction) is the product; push
 * is an add-on that must not be able to break it. The whole body is in a
 * try/catch(Throwable) that reports and swallows.
 *
 * ORDER (cheapest and most-often-false first — an ineligible type costs zero queries):
 *   1. {@see PushEligibility}            static; agency / excluded types stop here
 *   2. `$suppressPush`                   the caller already covers this news (AH-069 mirror)
 *   3. {@see PushNotificationsEnabled}   the send kill-switch
 *   4. push preference                   type-aware default; SKIPPED for always-on types
 *   5. live tokens                       none → stop (and no throttle slot is spent)
 *   6. message throttle                  message types only; once per (recipient, thread) / 60 s
 *   7. build once, one job per token
 *
 * Push is INDEPENDENT of the in-app preference: a user who muted in-app for a type
 * still gets its push unless they muted push.
 */
final class PushDispatcher
{
    /** Seconds a (recipient, thread) pair stays throttled after a message push. */
    public const int THROTTLE_SECONDS = 60;

    public function __construct(
        private readonly PushTokenRegistry $tokens,
        private readonly PushMessageFactory $messages,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the in-app `data` bag (the factory reads an allowlist from it)
     */
    public function dispatch(
        User $recipient,
        NotificationType $type,
        array $data,
        ?string $notificationUlid,
        bool $suppressPush = false,
    ): void {
        try {
            $this->run($recipient, $type, $data, $notificationUlid, $suppressPush);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function run(User $recipient, NotificationType $type, array $data, ?string $notificationUlid, bool $suppressPush): void
    {
        if (! PushEligibility::isEligible($type)) {
            return;
        }

        if ($suppressPush) {
            return;
        }

        if (! Feature::active(PushNotificationsEnabled::NAME)) {
            return;
        }

        if (! PushEligibility::isAlwaysOn($type) && ! $this->pushPreferenceEnabled($recipient, $type)) {
            return;
        }

        $tokens = $this->tokens->liveTokensFor($recipient);

        if ($tokens->isEmpty()) {
            return;
        }

        $threadKey = PushMessageFactory::threadKey($type, $data);

        if ($threadKey !== null && ! $this->claimThrottleSlot($recipient, $threadKey)) {
            return;
        }

        $message = $this->messages->make($type, $recipient, $data, $notificationUlid);

        if ($message === null) {
            return;
        }

        foreach ($tokens as $token) {
            SendPushNotificationJob::dispatch($token->id, $message, $type->value);
        }
    }

    /**
     * Stored row wins; absence resolves through the ONE type-aware default
     * ({@see NotificationChannel::defaultEnabledFor()}).
     */
    private function pushPreferenceEnabled(User $recipient, NotificationType $type): bool
    {
        $preference = NotificationPreference::query()
            ->where('user_id', $recipient->getKey())
            ->where('type', $type->value)
            ->where('channel', NotificationChannel::Push->value)
            ->first();

        return $preference === null
            ? NotificationChannel::Push->defaultEnabledFor($type)
            : $preference->is_enabled;
    }

    /**
     * Atomic claim (`Cache::add` is set-if-absent): true for the first message in
     * the window, false for the rest. Deliberately separate from the message
     * EMAIL debounce — it neither reads nor writes that store (a scan test pins it).
     */
    private function claimThrottleSlot(User $recipient, string $threadKey): bool
    {
        return Cache::add(
            'push:throttle:'.$recipient->getKey().':'.$threadKey,
            1,
            self::THROTTLE_SECONDS,
        );
    }
}
