<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Origins that receive stateful API authentication cookies. Phase 1 in
    | local dev runs both SPAs (main + admin) on 127.0.0.1 with different
    | Vite ports. Cookies are NOT isolated by port in browsers, so cookie-name
    | isolation is enforced separately — see config/session.php and the
    | UseAdminSessionCookie middleware (and docs/runbooks/local-dev.md).
    |
    | `localhost:5173` is included as a convenience alias for the main SPA so
    | a browser tab opened at http://localhost:5173 also gets stateful session
    | cookies; the canonical local-dev URL remains http://127.0.0.1:5173 per
    | the runbook. The admin SPA is intentionally NOT mirrored to
    | `localhost:5174` because UseAdminSessionCookie::originIsAdminSpa() only
    | accepts the canonical 127.0.0.1 admin URL — adding a localhost mirror
    | here without the matching middleware update would re-introduce the
    | d9f29eb admin-CSRF-419 bug under the `localhost` host.
    |
    | Production / staging stateful domains come from environment variables;
    | see docs/SPRINT-0-MANUAL-STEPS.md for AWS Secrets Manager wiring.
    |
    */

    'stateful' => explode(',', (string) env(
        'SANCTUM_STATEFUL_DOMAINS',
        'localhost,localhost:5173,127.0.0.1,127.0.0.1:5173,127.0.0.1:5174',
    )),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | Both SPAs use the session-cookie guard, but the admin SPA gets isolated
    | session cookies in local dev (see UseAdminSessionCookie middleware).
    | The 'web_admin' guard is otherwise identical to 'web' — distinct names
    | exist so policies and route groups can route by guard.
    |
    */

    'guard' => ['web', 'web_admin'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | Applies to personal access tokens only (the creator app, AH-099). SPA
    | sessions are governed by config('session.lifetime').
    |
    | A FIXED ceiling measured from token creation — Sanctum has no sliding
    | expiry and no refresh. Default 129600 min = 90 days. Revocation
    | (logout / password reset / suspension) is the real control; this caps
    | the blast radius of a leaked token that none of those caught.
    |
    | Deliberately a GLOBAL setting rather than a per-token `expires_at`:
    | Sanctum evaluates it at use time against `created_at`, so lowering the
    | env value is an operator lever that retroactively expires older
    | tokens. A per-token stamp could not do that.
    |
    */

    'expiration' => (int) env('SANCTUM_TOKEN_EXPIRATION_MINUTES', 129600),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    */

    'token_prefix' => (string) env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
