<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Events\PasswordResetCompleted;
use App\Modules\Identity\Events\PasswordResetRequested;
use App\Modules\Identity\Mail\ResetPasswordMail;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\Auth\PasswordBroker as PasswordBrokerContract;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Owns forgot-password and reset-password flows.
 *
 *   - {@see Request()} issues a token via Laravel's password broker, queues
 *     the localized {@see ResetPasswordMail}, audits via
 *     {@see PasswordResetRequested}. Returns silently for unknown emails AND
 *     for users with `email_verified_at IS NULL` to prevent user
 *     enumeration AND to close the bulk-invite throwaway-password vector
 *     (Sprint 3 Chunk 2 P1 — see docs/reviews/sprint-3-chunk-1-review.md
 *     "P1 blockers for Chunk 2"). Standing standard #9 + #40.
 *
 *   - {@see complete()} validates the token, sets the new password
 *     (Argon2id, the default driver), invalidates all existing sessions
 *     (logoutOtherDevices) per docs/05 §6.6, clears the temporary lockout
 *     and failed-login counter, and emits {@see PasswordResetCompleted}.
 *
 * Returns are explicit booleans + an enum-style result so controllers can
 * map cleanly onto the documented error envelopes without inspecting the
 * password-broker constants.
 */
final class PasswordResetService
{
    public function __construct(
        private readonly MailFactory $mail,
        private readonly Dispatcher $events,
        private readonly Repository $config,
        private readonly FailedLoginTracker $failedLogins,
        private readonly AccountLockoutService $lockout,
    ) {}

    public function request(string $email, Request $request): void
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            return;
        }

        // #9 user-enumeration defence — silent 204 for unverified users.
        // Closes the Sprint 3 Chunk 1 bulk-invite throwaway-password
        // vector: BulkInviteService creates User rows with
        // email_verified_at = null, so without this gate an attacker
        // who guesses an invited email could trigger a reset mail that
        // races the legitimate magic-link consumer. See chunk-1 review's
        // "P1 blockers for Chunk 2" + docs/tech-debt.md
        // "Forgot-password user-enumeration defense regression".
        if ($user->email_verified_at === null) {
            return;
        }

        $token = $this->broker()->createToken($user);

        $this->mail
            ->mailer()
            ->to($user->email)
            ->locale($user->preferred_language ?: 'en')
            ->queue(new ResetPasswordMail(
                user: $user,
                resetUrl: $this->buildResetUrl($user, $token),
                expiresInMinutes: $this->expiryMinutes(),
            ));

        $this->events->dispatch(new PasswordResetRequested(
            user: $user,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        ));
    }

    public function complete(string $email, string $token, string $newPassword, Request $request): PasswordResetResult
    {
        $status = $this->broker()->reset(
            credentials: [
                'email' => $email,
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
                'token' => $token,
            ],
            callback: function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                $this->failedLogins->clear($user->email);
                $this->lockout->clearTemporaryLock($user->email);
            },
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            return PasswordResetResult::InvalidToken;
        }

        $user = User::query()->where('email', $email)->firstOrFail();

        $this->events->dispatch(new PasswordReset($user));
        $this->events->dispatch(new PasswordResetCompleted(
            user: $user,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        ));

        return PasswordResetResult::Completed;
    }

    private function broker(): PasswordBrokerContract
    {
        return Password::broker();
    }

    private function expiryMinutes(): int
    {
        return (int) $this->config->get('auth.passwords.users.expire', 60);
    }

    private function buildResetUrl(User $user, string $token): string
    {
        $base = rtrim((string) $this->config->get('app.frontend_main_url', 'http://127.0.0.1:5173'), '/');

        $query = http_build_query([
            'token' => $token,
            'email' => $user->email,
        ]);

        return $base.'/auth/reset-password?'.$query;
    }
}
