<?php

declare(strict_types=1);

namespace App\Core\Impersonation;

/**
 * The four privilege-sensitive actions refused at the API while an admin is
 * impersonating a user (Sprint 13, D-10). Matched by route NAME so a surface
 * that ships later (payments are coming-soon this sprint) is covered the
 * moment its route lands — never UI-hidden.
 *
 * This list lives in Core (NOT in the Identity module) deliberately: the
 * patterns embed real Laravel route names like `auth.2fa.disable`, and the
 * Identity-module i18n harvest test (`i18n-auth-codes.spec.ts`) treats every
 * `auth.*` / `rate_limit.*` string literal under `app/Modules/Identity` as a
 * backend ERROR code that demands a translation. Route-name match patterns
 * are a different namespace; keeping them out of the Identity tree avoids a
 * false positive without polluting the translation bundles.
 */
final class HardBlockedActions
{
    /**
     * Route-name globs (fnmatch syntax), grouped by the privilege each
     * protects. Each group maps to one of the four review assertions.
     *
     * @var list<string>
     */
    public const ROUTE_PATTERNS = [
        // 1. Password change — the impersonator must never be able to lock the
        //    real user out by rotating their password.
        'auth.password.update',
        'auth.password.change',
        // 2. Two-factor disable / recovery-code rotation — the same
        //    credential-hijack surface.
        'auth.2fa.disable',
        'auth.2fa.recovery_codes',
        // 3. Contract signing — a legally-binding act of assent that must be
        //    the real user, never support acting "as" them.
        '*.contract.accept',
        '*.contract.click-through-accept',
        // 4. Payment release — moving money. Coming-soon this sprint; the name
        //    glob arms the block ahead of the surface landing.
        '*.payout.release',
        'payments.*.release',
        'payments.release',
    ];

    public static function matches(?string $routeName): bool
    {
        if (! is_string($routeName)) {
            return false;
        }

        foreach (self::ROUTE_PATTERNS as $pattern) {
            if (fnmatch($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }
}
