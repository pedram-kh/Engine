<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Files;

/**
 * The only door the erasure takes to object storage (Stage 4f).
 *
 * Two operations and no more: list the keys under one creator-exclusive prefix
 * and delete one key. There is deliberately no "delete a prefix": a shared
 * prefix (`messages/{thread}/`) holds the agency's objects too, so every delete
 * is of one named key the erasure itself decided on.
 */
interface ObjectStore
{
    /**
     * Every key under the prefix, recursively. Reads only.
     *
     * @return list<string>
     */
    public function list(string $prefix): array;

    /**
     * Delete one key, and every stored version of it when the store keeps
     * versions. A key that is already gone counts as done (idempotent).
     *
     * @throws ObjectDeletionFailed
     */
    public function delete(string $key): void;
}
