<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Creators\Services\CreatorBootstrapService;
use App\Modules\Identity\Enums\ThemePreference;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Events\EmailVerificationSent;
use App\Modules\Identity\Events\UserSignedUp;
use App\Modules\Identity\Listeners\WriteAuthAuditLog;
use App\Modules\Identity\Mail\VerifyEmailMail;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Rules\PasswordIsNotBreached;
use App\Modules\Identity\Rules\StrongPassword;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Self-serve sign-up. Phase 1 only ever creates a creator-typed user;
 * agency invitations and admin provisioning live in their own services.
 *
 * Strict invariants:
 *
 *   - Creates the User row; delegates Creator-row bootstrap to
 *     {@see CreatorBootstrapService} (see
 *     app/Modules/Creators/Services/CreatorBootstrapService.php). Single
 *     transaction guarantees no User-without-Creator state. No other
 *     satellite profile rows (admin_profiles, agency_users) are touched
 *     here — those are owned by their respective modules.
 *
 *   - NO authentication side effects: no session cookie, no
 *     `Auth::login()`, no `last_login_*` stamping. The user must
 *     verify their email and then sign in separately
 *     (docs/05-SECURITY-COMPLIANCE.md §6.5).
 *
 *   - Password is hashed via the configured driver (Argon2id by default,
 *     see `config/hashing.php`). The HIBP and length rules are enforced
 *     at the Form Request layer via {@see StrongPassword}
 *     and {@see PasswordIsNotBreached} —
 *     the same rules used by password reset.
 *
 *   - Email is normalised (lower-cased, trimmed) before insert so the
 *     unique index on `users.email` actually catches case-variant
 *     duplicates the Form Request let through.
 *
 *   - Two events fire in order: {@see UserSignedUp} then
 *     {@see EmailVerificationSent}. Audit rows are written by
 *     {@see WriteAuthAuditLog}.
 */
final class SignUpService
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly MailFactory $mail,
        private readonly Repository $config,
        private readonly EmailVerificationToken $tokens,
        private readonly CreatorBootstrapService $creatorBootstrap,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  validated payload
     */
    public function register(array $attributes, Request $request): User
    {
        $email = strtolower(trim((string) $attributes['email']));
        $name = trim((string) $attributes['name']);
        $password = (string) $attributes['password'];
        $preferredLanguage = $this->normaliseLanguage($attributes['preferred_language'] ?? null);

        /** @var User $user */
        $user = DB::transaction(function () use ($email, $name, $password, $preferredLanguage): User {
            $user = User::query()->create([
                'email' => $email,
                'name' => $name,
                'password' => $password,
                'type' => UserType::Creator,
                'preferred_language' => $preferredLanguage,
                'preferred_currency' => $this->config->get('app.default_currency', 'EUR'),
                'timezone' => $this->config->get('app.timezone', 'UTC'),
                'theme_preference' => ThemePreference::System,
                'mfa_required' => false,
                'is_suspended' => false,
                'email_verified_at' => null,
            ]);

            // Bootstrap the Creator satellite row in the same transaction.
            // Failure to create the Creator row aborts the User insert too,
            // so the database never holds a creator-typed User without
            // a corresponding Creator row.
            $this->creatorBootstrap->bootstrapForUser($user);

            return $user;
        });

        $this->events->dispatch(new UserSignedUp(
            user: $user,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        ));

        $this->sendVerificationMail($user, $request);

        return $user;
    }

    public function sendVerificationMail(User $user, Request $request): void
    {
        $token = $this->tokens->mint($user);

        $this->mail
            ->mailer()
            ->to($user->email)
            ->locale($user->preferred_language ?: 'en')
            ->queue(new VerifyEmailMail(
                user: $user,
                verifyUrl: $this->buildVerifyUrl($token),
                expiresInHours: EmailVerificationToken::LIFETIME_HOURS,
            ));

        $this->events->dispatch(new EmailVerificationSent(
            user: $user,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        ));
    }

    private function normaliseLanguage(mixed $candidate): string
    {
        $allowed = ['en', 'pt', 'it'];
        $value = is_string($candidate) ? strtolower(trim($candidate)) : '';

        return in_array($value, $allowed, true) ? $value : 'en';
    }

    private function buildVerifyUrl(string $token): string
    {
        $base = rtrim((string) $this->config->get('app.frontend_main_url', 'http://127.0.0.1:5173'), '/');

        return $base.'/auth/verify-email?'.http_build_query(['token' => $token]);
    }
}
