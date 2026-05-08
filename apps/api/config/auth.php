<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        // Main SPA guard. Issues a session cookie named per
        // config('session.cookie') — see SetTenancyContext middleware
        // and docs/runbooks/local-dev.md for cookie isolation in local dev.
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        // Admin SPA guard. Same Sanctum-cookie pattern as 'web' but isolated
        // by a distinct cookie name in local dev (set by the
        // UseAdminSessionCookie middleware before StartSession), and by a
        // separate subdomain in staging/production. Per
        // docs/00-MASTER-ARCHITECTURE.md §7 and docs/05-SECURITY-COMPLIANCE.md §4.
        'web_admin' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the amount of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

    /*
    |--------------------------------------------------------------------------
    | Mandatory MFA enforcement on the admin guard
    |--------------------------------------------------------------------------
    |
    | When true, every route mounted behind the EnsureMfaForAdmins
    | middleware (i.e. all admin routes other than the 2FA enrollment
    | endpoints themselves) refuses to serve admins who have not yet
    | confirmed 2FA. The default is `true` and SHOULD remain so in
    | every staging / production environment — chunk 5 priority #11.
    |
    | The flag MAY be flipped to false in the local environment via
    | the env var below to unblock UI development against a fresh
    | unenrolled admin account. EnsureMfaForAdmins refuses to honour
    | the override outside `local`, so a misconfigured staging env
    | cannot silently disable MFA.
    |
    */

    'admin_mfa_enforced' => (bool) env('ADMIN_MFA_ENFORCED', true),

];
