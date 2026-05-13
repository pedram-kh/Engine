<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Exceptions;

use RuntimeException;

/**
 * Thrown by the Deferred*Provider stubs when a contract method is invoked
 * before Sprint 3 Chunk 2 binds the real (mock) implementation.
 *
 * Sprint 3 Chunk 1 ships only the contract interfaces + Deferred stubs.
 * Chunk 2 swaps the binding in CreatorsServiceProvider to the
 * Mock{Kind}Provider implementations.
 *
 * The exception name is intentionally explicit so a runtime call from a
 * wizard endpoint surfaces a clear "you forgot to wire the provider"
 * error rather than a vague missing-method or null-return downstream.
 */
final class ProviderNotBoundException extends RuntimeException
{
    public static function for(string $providerName, string $method): self
    {
        return new self(sprintf(
            "Integration provider '%s' is not bound (Sprint 3 Chunk 2 wires the Mock implementation). Method called: %s.",
            $providerName,
            $method,
        ));
    }
}
