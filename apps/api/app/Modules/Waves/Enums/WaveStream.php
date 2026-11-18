<?php

declare(strict_types=1);

namespace App\Modules\Waves\Enums;

/**
 * The three streams of a wave's tracker (PL-1.3, frame 01b). The enum is the constraint on `wave_steps.stream`
 * (a plain string column), so a new case needs no migration. A stream is present when it has at least one step.
 */
enum WaveStream: string
{
    case UgcVideo = 'ugc_video';
    case Statics = 'statics';
    case Stills = 'stills';

    /** Display order of the stream cards. */
    public function order(): int
    {
        return match ($this) {
            self::UgcVideo => 1,
            self::Statics => 2,
            self::Stills => 3,
        };
    }

    /** The colour of the stream swatch (frame 01b). */
    public function colour(): string
    {
        return match ($this) {
            self::UgcVideo => '#3ecf9e',
            self::Statics => '#8b6cff',
            self::Stills => '#f472b6',
        };
    }

    /** The UGC video stream carries the six strategy steps the Brand page bar reads, so it cannot be removed. */
    public function isRemovable(): bool
    {
        return $this !== self::UgcVideo;
    }
}
