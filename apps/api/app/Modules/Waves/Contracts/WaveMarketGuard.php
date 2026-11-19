<?php

declare(strict_types=1);

namespace App\Modules\Waves\Contracts;

use App\Modules\Waves\Models\Wave;

/**
 * PL-2.1 — lets the Waves module ask "does anything still hang off these markets?" without reading another module's
 * tables. Implemented by the Strategy module (personas are per market) and bound in its service provider whether or not
 * the strategy flag is on. Called by `WaveService::update` INSIDE its transaction, on the row-locked wave, so it is
 * serialised with every strategy write (they take the same lock first).
 */
interface WaveMarketGuard
{
    /**
     * @param  Wave  $locked  the wave, already locked FOR UPDATE by the caller
     * @param  list<string>  $removedCodes  market codes the edit would remove
     * @return array<string, int> market code => number of personas, only for markets with at least one
     */
    public function personaCountsByMarket(Wave $locked, array $removedCodes): array;
}
