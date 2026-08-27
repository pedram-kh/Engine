<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Authentication Language Lines (en)
|--------------------------------------------------------------------------
|
| Translation keys consumed by Identity-module rules, controllers, and
| mailables. The SPAs render these via the `code` field of every error
| envelope (docs/04-API-DESIGN.md §8) and via the `Accept-Language` header
| Laravel resolves at the start of every request.
|
| Pair each new key with a translation in pt/auth.php and it/auth.php.
| If you add a key here without translating the others, CI would catch it
| once the i18n linter lands in Sprint 8 — until then, keep the three
| files in lock-step manually.
|
*/

return [
    'password' => [
        'invalid_type' => 'The password must be a string.',
        'too_short' => 'The password must be at least :min characters.',
        'too_long' => 'The password must not exceed :max characters.',
        'breached' => 'This password appears in known data breaches and cannot be used. Please choose a different one.',
    ],

    'login' => [
        'invalid_credentials' => 'Invalid email or password.',
        'mfa_required' => 'Multi-factor authentication is required to complete sign-in.',
        'account_locked_temporary' => 'Too many failed sign-in attempts. Please try again in :minutes minutes.',
        'account_locked' => 'This account has been locked. Reset your password or contact support to regain access.',
        'rate_limited' => 'Too many requests. Please try again in :seconds seconds.',
        'wrong_spa' => 'This account is not registered for this site. Please sign in on the correct site.',
    ],

    'reset' => [
        'subject' => 'Reset your :app password',
        'greeting' => 'Hello :name,',
        'body' => 'We received a request to reset the password on your :app account. The link below is valid for :minutes minutes.',
        'cta' => 'Reset password',
        'ignore' => 'If you did not request this, you can safely ignore this email — your password will not change.',
        'invalid_token' => 'This password-reset link is invalid or has expired. Request a new one.',
        'completed' => 'Your password has been reset. All other active sessions have been signed out.',
    ],

    'email_verification' => [
        'subject' => 'Verify your :app email address',
        'greeting' => 'Welcome to :app, :name!',
        'body' => 'Please confirm your email address to finish setting up your :app account. The link below is valid for :hours hours.',
        'cta' => 'Verify email address',
        'ignore' => 'If you did not create a :app account, you can safely ignore this email.',
        'verification_invalid' => 'This verification link is invalid. Request a new one.',
        'verification_expired' => 'This verification link has expired. Request a new one.',
        'already_verified' => 'This email address has already been verified.',
    ],

    'signup' => [
        'email_taken' => 'An account with this email address already exists.',
    ],

    'mfa' => [
        'invalid_code' => 'The two-factor code is invalid. Please try again.',
        'rate_limited' => 'Too many invalid two-factor attempts. Please try again in :minutes minutes.',
        'enrollment_suspended' => 'Two-factor authentication has been suspended for this account. Please contact support to restore access.',
        'enrollment_required' => 'Two-factor authentication must be enabled before you can continue.',
        'already_enabled' => 'Two-factor authentication is already enabled for this account.',
        'not_enabled' => 'Two-factor authentication is not enabled for this account.',
        'provisional_expired' => 'The two-factor enrollment session has expired. Please start again.',
    ],

    // AH-087 — the three admin-initiated 2FA notices. The admin's reason is
    // never quoted and the acting admin is never named (the AH-051 eyes-on
    // lesson): the reason is the internal paper trail, not user-facing copy.
    'mfa_admin' => [
        'reset' => [
            'subject' => 'Your two-factor authentication was reset',
            'greeting' => 'Hi :name,',
            'body' => 'A platform administrator reset two-factor authentication on your :app account. Your previous authenticator app and recovery codes no longer work.',
            'next' => 'You will be asked to set two-factor authentication up again the next time you reach a protected part of the app.',
            'unexpected' => 'If you did not request this, contact support immediately.',
            'cta' => 'Go to :app',
        ],
        'exempted' => [
            'subject' => 'Two-factor authentication is no longer required on your account',
            'greeting' => 'Hi :name,',
            'body' => 'A platform administrator removed the two-factor authentication requirement from your :app account. You can now use the areas that previously asked you to set it up without enrolling.',
            'still_enrolled' => 'If you already have two-factor authentication set up, it stays active and you will still be asked for a code when you sign in — you can keep it or turn it off in your settings.',
            'unexpected' => 'If you did not expect this change, contact support.',
        ],
        'exemption_removed' => [
            'subject' => 'Two-factor authentication is required on your account again',
            'greeting' => 'Hi :name,',
            'body' => 'A platform administrator restored the two-factor authentication requirement on your :app account.',
            'next' => 'The next time you reach a protected area you will be asked to set two-factor authentication up, if you have not already.',
            'unexpected' => 'If you did not expect this change, contact support.',
        ],
    ],
];
