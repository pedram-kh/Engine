<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Modules\Identity\Contracts\PwnedPasswordsClientContract;
use App\Modules\Identity\Events\EmailVerificationSent;
use App\Modules\Identity\Events\EmailVerified;
use App\Modules\Identity\Events\LoginFailed;
use App\Modules\Identity\Events\PasswordResetCompleted;
use App\Modules\Identity\Events\PasswordResetRequested;
use App\Modules\Identity\Events\TwoFactorConfirmed;
use App\Modules\Identity\Events\TwoFactorDisabled;
use App\Modules\Identity\Events\TwoFactorEnabled;
use App\Modules\Identity\Events\TwoFactorRecoveryCodeConsumed;
use App\Modules\Identity\Events\TwoFactorRecoveryCodesRegenerated;
use App\Modules\Identity\Events\UserLoggedIn;
use App\Modules\Identity\Events\UserLoggedOut;
use App\Modules\Identity\Events\UserSignedUp;
use App\Modules\Identity\Listeners\WriteAuthAuditLog;
use App\Modules\Identity\Services\PwnedPasswordsClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PwnedPasswordsClientContract::class, PwnedPasswordsClient::class);
    }

    public function boot(): void
    {
        $this->registerRateLimits();
        $this->registerEventListeners();
        $this->registerRoutes();
    }

    private function registerRateLimits(): void
    {
        // Unauthenticated auth endpoints: 10 requests / minute / IP
        // (docs/04-API-DESIGN.md §13).
        RateLimiter::for('auth-ip', static fn (Request $request): Limit => Limit::perMinute(10)
            ->by((string) $request->ip())
            ->response(static fn (Request $req, array $headers) => response()->json([
                'errors' => [[
                    'status' => '429',
                    'code' => 'rate_limit.exceeded',
                    'title' => trans('auth.login.rate_limited', [
                        'seconds' => (int) ($headers['Retry-After'] ?? 60),
                    ]),
                ]],
            ], 429, $headers)));

        // Login endpoint: an additional 5 requests / minute / email
        // (docs/05-SECURITY-COMPLIANCE.md §6.2). Keyed on the lower-cased
        // email + IP so attempts against many accounts from one IP can't
        // share the email bucket and silently exceed the documented cap.
        RateLimiter::for('auth-login-email', static function (Request $request): Limit {
            $email = strtolower(trim((string) $request->input('email', '')));

            return Limit::perMinute(5)
                ->by('login:'.$email.':'.$request->ip())
                ->response(static fn (Request $req, array $headers) => response()->json([
                    'errors' => [[
                        'status' => '429',
                        'code' => 'rate_limit.exceeded',
                        'title' => trans('auth.login.rate_limited', [
                            'seconds' => (int) ($headers['Retry-After'] ?? 60),
                        ]),
                    ]],
                ], 429, $headers));
        });

        // Password-reset request endpoint reuses the per-IP cap with a
        // tighter ceiling so a single attacker cannot mailbomb a victim.
        RateLimiter::for('auth-password', static fn (Request $request): Limit => Limit::perMinute(5)
            ->by('pw:'.$request->ip())
            ->response(static fn (Request $req, array $headers) => response()->json([
                'errors' => [[
                    'status' => '429',
                    'code' => 'rate_limit.exceeded',
                    'title' => trans('auth.login.rate_limited', [
                        'seconds' => (int) ($headers['Retry-After'] ?? 60),
                    ]),
                ]],
            ], 429, $headers)));

        // Resend-verification: at most ONE request per minute per email,
        // applied on top of the per-IP cap. The strict ceiling keeps a
        // single attacker from mailbombing a known address even if they
        // rotate IPs through a residential proxy pool.
        RateLimiter::for('auth-resend-verification', static function (Request $request): Limit {
            $email = strtolower(trim((string) $request->input('email', '')));

            return Limit::perMinute(1)
                ->by('verify:'.$email)
                ->response(static fn (Request $req, array $headers) => response()->json([
                    'errors' => [[
                        'status' => '429',
                        'code' => 'rate_limit.exceeded',
                        'title' => trans('auth.login.rate_limited', [
                            'seconds' => (int) ($headers['Retry-After'] ?? 60),
                        ]),
                    ]],
                ], 429, $headers));
        });
    }

    private function registerEventListeners(): void
    {
        Event::listen(UserLoggedIn::class, [WriteAuthAuditLog::class, 'handleUserLoggedIn']);
        Event::listen(UserLoggedOut::class, [WriteAuthAuditLog::class, 'handleUserLoggedOut']);
        Event::listen(LoginFailed::class, [WriteAuthAuditLog::class, 'handleLoginFailed']);
        Event::listen(PasswordResetRequested::class, [WriteAuthAuditLog::class, 'handlePasswordResetRequested']);
        Event::listen(PasswordResetCompleted::class, [WriteAuthAuditLog::class, 'handlePasswordResetCompleted']);
        Event::listen(UserSignedUp::class, [WriteAuthAuditLog::class, 'handleUserSignedUp']);
        Event::listen(EmailVerificationSent::class, [WriteAuthAuditLog::class, 'handleEmailVerificationSent']);
        Event::listen(EmailVerified::class, [WriteAuthAuditLog::class, 'handleEmailVerified']);
        Event::listen(TwoFactorEnabled::class, [WriteAuthAuditLog::class, 'handleTwoFactorEnabled']);
        Event::listen(TwoFactorConfirmed::class, [WriteAuthAuditLog::class, 'handleTwoFactorConfirmed']);
        Event::listen(TwoFactorDisabled::class, [WriteAuthAuditLog::class, 'handleTwoFactorDisabled']);
        Event::listen(TwoFactorRecoveryCodesRegenerated::class, [WriteAuthAuditLog::class, 'handleTwoFactorRecoveryCodesRegenerated']);
        Event::listen(TwoFactorRecoveryCodeConsumed::class, [WriteAuthAuditLog::class, 'handleTwoFactorRecoveryCodeConsumed']);
        // Note: TwoFactorEnrollmentSuspended is intentionally NOT
        // listened to here. Its `mfa.enrollment_suspended` audit row is
        // written transactionally by TwoFactorVerificationThrottle so
        // the suspension state and audit log can never disagree (same
        // pattern as AccountLocked).
    }

    private function registerRoutes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/Routes/api.php');
    }
}
