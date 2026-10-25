<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Features;

use Closure;

/**
 * AH-104c C3 — the admin's Erase button and its endpoint.
 *
 * Off by default, and off at deploy: Pedram turns it on in the admin Feature-flags page (with a
 * reason, which the page records) for one named account and turns it off again after. It gates
 * the button's `POST .../erase` and nothing else: the queue, the hold, the plan view and the
 * `erasure:run` command do not read it, and a run already claimed is never stopped by it (a
 * switch that could strand a claimed account half-finished would be worse than no switch).
 */
final class AccountErasureExecuteEnabled
{
    public const NAME = 'account_erasure_execute_enabled';

    /** Pennant stores a non-Closure default as-is, so the default MUST be a Closure. */
    public static function default(): Closure
    {
        return static fn (mixed $scope = null): bool => false;
    }
}
