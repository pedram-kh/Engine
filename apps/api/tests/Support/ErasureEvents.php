<?php

declare(strict_types=1);

namespace Tests\Support;

/** A shared, ordered log the fakes write to, so a spec can assert the order of the stages. */
final class ErasureEvents
{
    /** @var list<string> */
    public array $log = [];

    public function add(string $event): void
    {
        $this->log[] = $event;
    }

    /** @return list<string> the log with consecutive repeats collapsed */
    public function collapsed(): array
    {
        $out = [];
        foreach ($this->log as $event) {
            if ($out === [] || $out[array_key_last($out)] !== $event) {
                $out[] = $event;
            }
        }

        return $out;
    }
}
