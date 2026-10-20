<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Erasure\Files\ObjectDeletionFailed;
use App\Modules\Erasure\Files\ObjectStore;

/** An ObjectStore that records every call, can be told to fail, and can log into a shared event list. */
final class RecordingObjectStore implements ObjectStore
{
    /** @var list<string> */
    public array $deleted = [];

    /** @var list<string> */
    public array $listed = [];

    public ?int $failOnDelete = null;

    public function __construct(private readonly ObjectStore $inner, private readonly ?ErasureEvents $events = null) {}

    public function list(string $prefix): array
    {
        $this->listed[] = $prefix;

        return $this->inner->list($prefix);
    }

    public function delete(string $key): void
    {
        if ($this->failOnDelete !== null && count($this->deleted) + 1 === $this->failOnDelete) {
            throw ObjectDeletionFailed::forObject();
        }
        if ($this->events !== null) {
            $this->events->add('objects');
        }
        $this->deleted[] = $key;
        $this->inner->delete($key);
    }
}
