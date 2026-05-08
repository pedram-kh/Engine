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
    | Production / staging stateful domains come from environment variables;
    | see docs/SPRINT-0-MANUAL-STEPS.md for AWS Secrets Manager wiring.
    |
    */

    'stateful' => explode(',', (string) env(
        'SANCTUM_STATEFUL_DOMAINS',
        'localhost,127.0.0.1,127.0.0.1:5173,127.0.0.1:5174',
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
    | Used only for personal access tokens (Phase 2 mobile / Phase 3 public
    | API). SPA sessions are governed by config('session.lifetime').
    |
    */

    'expiration' => null,

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
