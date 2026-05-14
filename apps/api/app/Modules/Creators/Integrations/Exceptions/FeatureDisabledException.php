<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Exceptions;

use RuntimeException;

/**
 * Thrown by the Skipped*Provider stubs when a contract method is invoked
 * while the gating Pennant feature flag is OFF.
 *
 * Sprint 3 Chunk 2 introduces three Skipped*Provider stubs (Kyc, Esign,
 * Payment) that {@see CreatorsServiceProvider} binds when the
 * corresponding `*_enabled` flag is OFF. Wizard endpoints check the
 * flag BEFORE invoking the provider and route to a skip-path; the
 * Skipped*Provider exception is the defence-in-depth backstop that
 * catches any code path that bypasses the flag check (#40 +
 * "no silent vendor calls" per docs/feature-flags.md). It is
 * conceptually distinct from {@see ProviderNotBoundException}, which
 * fires before any provider wiring is in place at all.
 *
 * The exception is constructed via the static `for()` factory so the
 * caller surfaces both the contract name and the method that was
 * attempted, mirroring the Deferred*Provider pattern.
 */
final class FeatureDisabledException extends RuntimeException
{
    public static function for(string $providerName, string $featureName, string $method): self
    {
        return new self(sprintf(
            "Integration provider '%s' is bound to a Skipped stub because feature flag '%s' is OFF. Method called: %s. Wizard endpoints must check the flag before invoking the provider (docs/feature-flags.md \"No silent vendor calls\").",
            $providerName,
            $featureName,
            $method,
        ));
    }
}
