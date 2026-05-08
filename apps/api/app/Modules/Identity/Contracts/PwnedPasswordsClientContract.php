<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Identity\Rules\PasswordIsNotBreached;

/**
 * Contract for any client that resolves "is this password known to be
 * breached?" against the HaveIBeenPwned k-anonymity API.
 *
 * Implementations MUST:
 *   - Hash the plaintext locally with SHA-1.
 *   - Send only the first 5 hex characters of the hash to the upstream
 *     service. Never the full hash. Never the plaintext password.
 *   - Compare the returned suffix list against the local 35-character
 *     suffix to decide breach status.
 *
 * The contract exists so {@see PasswordIsNotBreached}
 * and any future internal callers (signup, password change, password reset)
 * resolve a swappable binding instead of the concrete client. Tests bind
 * an in-memory fake; the production binding lives in
 * {@see IdentityServiceProvider}.
 */
interface PwnedPasswordsClientContract
{
    /**
     * Returns the count of breaches the password appears in. 0 means safe.
     * Any positive integer means rejected.
     *
     * Implementations MAY return 0 on transient upstream failures (fail-open)
     * — at signup we'd rather accept a password than block a real user
     * because of an HIBP outage. Document the fallback in the implementation.
     */
    public function breachCount(string $plaintextPassword): int;
}
