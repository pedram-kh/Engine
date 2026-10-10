<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push\Fcm;

use RuntimeException;

/**
 * Internal to the FCM client: our credentials/config are unusable. Always caught
 * inside {@see FcmPushSender} and turned into a ConfigError result — it never
 * escapes into a request or a queue worker.
 */
final class FcmConfigException extends RuntimeException {}
