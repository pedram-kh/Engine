<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push;

/**
 * The handset platform a push token belongs to. Mirrors the client's
 * `Capacitor.getPlatform()` for the two native shells; the wire value is what
 * `POST /me/push-tokens` accepts.
 */
enum PushPlatform: string
{
    case Ios = 'ios';
    case Android = 'android';
}
