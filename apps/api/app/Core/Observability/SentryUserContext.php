<?php

declare(strict_types=1);

namespace App\Core\Observability;

use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Sentry\Event;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Throwable;

/**
 * Who the Sentry event is about (AH-121 C1, docs/reviews/sentry-plan.md B1 rule 6).
 *
 * The user is resolved when an EVENT IS BUILT, from the guards that ALREADY
 * hold an authenticated user for this request (`hasUser()`: no query, no
 * session read, nothing that could fail while an error is being reported).
 * That gives, with no per-request state to forget to clear:
 *
 *  - web, admin (web_admin) and token (creator_app) guards: {id: users.ulid}
 *    plus the `user_type` tag (creator / agency / admin). Under
 *    impersonation the authenticated user is the impersonated one.
 *  - guests: no user (a guard that is signed out holds no user, so sign-out
 *    clears it the same way).
 *  - queue jobs: no user, even when a request's user is still in memory
 *    (sync queue); scheduled commands have no guard user.
 *
 * SentryScrubber::beforeSend is the second wall: it keeps `id` only, and only
 * when it is a ulid.
 */
final class SentryUserContext
{
    /** Guards checked, in order. */
    private const GUARDS = ['web_admin', 'web', 'creator_app'];

    private static bool $processorRegistered = false;

    private static bool $inJob = false;

    /**
     * Called on every application boot (AppServiceProvider::boot).
     */
    public static function register(Dispatcher $events): void
    {
        self::$inJob = false;

        $events->listen(JobProcessing::class, static function (): void {
            self::$inJob = true;
        });
        $events->listen(JobProcessed::class, static function (): void {
            self::$inJob = false;
        });

        // The processor is process-global in the SDK; register it once.
        if (! self::$processorRegistered) {
            self::$processorRegistered = true;
            Scope::addGlobalEventProcessor(static fn (Event $event): Event => self::apply($event));
        }
    }

    public static function apply(Event $event): Event
    {
        $user = self::$inJob ? null : self::authenticatedUser();

        if ($user === null) {
            $event->setUser(null);

            return $event;
        }

        $event->setUser(UserDataBag::createFromUserIdentifier($user->ulid));

        $type = self::tagFor($user->type);
        if ($type !== null) {
            $event->setTag('user_type', $type);
        }

        return $event;
    }

    public static function tagFor(UserType $type): ?string
    {
        return match ($type) {
            UserType::Creator => 'creator',
            UserType::AgencyUser => 'agency',
            UserType::PlatformAdmin => 'admin',
            // Reserved for Phase 2: tagged nothing rather than inventing a value.
            UserType::BrandUser => null,
        };
    }

    private static function authenticatedUser(): ?User
    {
        foreach (self::GUARDS as $name) {
            try {
                $guard = Auth::guard($name);

                if (method_exists($guard, 'hasUser') && $guard->hasUser()) {
                    $user = $guard->user();

                    if ($user instanceof User) {
                        return $user;
                    }
                }
            } catch (Throwable) {
                // Never let user resolution break error reporting.
                continue;
            }
        }

        return null;
    }
}
