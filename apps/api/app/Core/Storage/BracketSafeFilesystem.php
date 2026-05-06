<?php

declare(strict_types=1);

namespace App\Core\Storage;

use Illuminate\Filesystem\Filesystem;

/**
 * Filesystem decorator that works correctly when the project's absolute path
 * contains literal brackets like "[PROJECT]".
 *
 * PHP's native glob() interprets unescaped `[` and `]` characters in the
 * pattern as character classes, which causes Laravel's migrator, config
 * loader, and translation loader to silently return empty matches when the
 * project root contains such characters.
 *
 * This class falls back to a scandir + fnmatch implementation whenever the
 * directory portion of the pattern contains brackets. The basename portion
 * (e.g. "*_*.php") never contains brackets in Laravel's call sites, so
 * fnmatch on the basename is safe.
 */
class BracketSafeFilesystem extends Filesystem
{
    public function glob($pattern, $flags = 0)
    {
        $directory = dirname($pattern);
        $basename = basename($pattern);

        $directoryHasBrackets = str_contains($directory, '[') || str_contains($directory, ']');

        if (! $directoryHasBrackets) {
            $result = glob($pattern, $flags);

            return $result === false ? [] : $result;
        }

        if (! is_dir($directory)) {
            return [];
        }

        $entries = scandir($directory);

        if ($entries === false) {
            return [];
        }

        $matches = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (fnmatch($basename, $entry)) {
                $matches[] = $directory.DIRECTORY_SEPARATOR.$entry;
            }
        }

        sort($matches);

        return $matches;
    }
}
