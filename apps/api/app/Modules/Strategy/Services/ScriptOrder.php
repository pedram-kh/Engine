<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

/**
 * The reorder rule shared by hooks, lines, B-roll rows and images (PL-3.2b, ruling 7): the request carries the COMPLETE id list;
 * it is accepted only when it is exactly the set that exists now. A missing, extra, duplicate or foreign id makes the whole request
 * an `order_mismatch`, and nothing is written.
 */
final class ScriptOrder
{
    /**
     * @param  list<string>  $current  the ulids that exist now
     * @param  array<int, mixed>  $sent
     */
    public static function sameSet(array $current, array $sent): bool
    {
        if (count($current) !== count($sent)) {
            return false;
        }
        foreach ($sent as $id) {
            if (! is_string($id)) {
                return false;
            }
        }
        $a = $current;
        $b = array_values($sent);
        sort($a);
        sort($b);

        return $a === $b;
    }
}
