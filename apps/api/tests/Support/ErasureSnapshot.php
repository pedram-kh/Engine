<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A hash of every row of every table, to prove "nothing was written". */
final class ErasureSnapshot
{
    /**
     * @param  list<string>  $except  tables left out
     * @return array<string, string>
     */
    public static function tables(array $except = []): array
    {
        $out = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if (in_array($name, $except, true)) {
                continue;
            }
            $rows = DB::table($name)->get()->map(fn ($r): string => json_encode($r, JSON_THROW_ON_ERROR))->sort()->values()->all();
            $out[$name] = md5(json_encode($rows, JSON_THROW_ON_ERROR));
        }
        ksort($out);

        return $out;
    }
}
