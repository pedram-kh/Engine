<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

/**
 * A user's preferred light/dark theme. Persisted on users.theme_preference
 * (docs/03-DATA-MODEL.md §2). `System` defers the choice to the OS-level
 * preference reported by the SPA at runtime.
 */
enum ThemePreference: string
{
    case Light = 'light';
    case Dark = 'dark';
    case System = 'system';
}
