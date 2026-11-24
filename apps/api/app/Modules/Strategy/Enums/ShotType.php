<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Enums;

/**
 * The ten shot types of a script hook (PL-3.2, plan 1.2), in the order the editor lists them. The values are the CHECK of
 * `wave_script_hooks.shot_type`; the TS union in `@catalyst/api-client` repeats them and a parity test keeps both equal.
 */
enum ShotType: string
{
    case Wide = 'wide';
    case Medium = 'medium';
    case CloseUp = 'close_up';
    case Detail = 'detail';
    case Pov = 'pov';
    case OverTheShoulder = 'over_the_shoulder';
    case TopDown = 'top_down';
    case ScreenRecording = 'screen_recording';
    case Reaction = 'reaction';
    case CreatorToCamera = 'creator_to_camera';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
