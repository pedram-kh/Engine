<?php

declare(strict_types=1);

namespace App\Modules\Waves\Contracts;

use App\Modules\Waves\Models\Wave;

/**
 * PL-2.1 — lets the Waves module ask "does anything still hang off these markets?" without reading another module's
 * tables. Implemented by the Strategy module (personas are per market) and bound in its service provider whether or not
 * the strategy flag is on. Called by `WaveService::update` INSIDE its transaction, on the row-locked wave, so it is
 * serialised with every strategy write (they take the same lock first).
 *
 * PL-2.4: markets are rows, so the guard takes and returns market ids. PL-3 adds a second method on this interface for a
 * market's COUNTRIES ("does anything hang off the countries this edit removes?", ruling Q10); nothing depends on a
 * country today.
 */
interface WaveMarketGuard
{
    /**
     * @param  Wave  $locked  the wave, already locked FOR UPDATE by the caller
     * @param  list<int>  $removedIds  ids of the markets the edit would remove
     * @return array<int, int> market id => number of personas, only for markets with at least one
     */
    public function personaCountsByMarket(Wave $locked, array $removedIds): array;

    /**
     * PL-2.3: the concepts of those markets, so the refusal can name them. A market with concepts always has personas
     * (a persona with concepts cannot be deleted), so this never decides the refusal; it only adds a count to it.
     *
     * @param  Wave  $locked  the wave, already locked FOR UPDATE by the caller
     * @param  list<int>  $removedIds
     * @return array<int, int> market id => number of concepts, only for markets with at least one
     */
    public function conceptCountsByMarket(Wave $locked, array $removedIds): array;
}
