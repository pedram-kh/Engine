<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push\Fcm;

use RuntimeException;

/**
 * Internal to the FCM client: a transient upstream failure (timeout, 5xx) while
 * obtaining credentials. Always caught inside {@see FcmPushSender} → Retryable.
 */
final class FcmTransientException extends RuntimeException {}
