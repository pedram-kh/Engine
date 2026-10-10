<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push;

/**
 * What happened to one send attempt, in terms the job can act on without
 * knowing any vendor's error vocabulary.
 */
enum PushOutcome: string
{
    /** The provider accepted the message for delivery. */
    case Delivered = 'delivered';

    /** The provider says THIS token is dead (uninstalled, rotated). Invalidate it; never retry. */
    case TokenInvalid = 'token_invalid';

    /** Transient (quota, 5xx, timeout). Safe to retry with backoff. */
    case Retryable = 'retryable';

    /** OUR credentials/config are wrong. Alarm; never invalidate a token; a retry cannot fix it. */
    case ConfigError = 'config_error';

    /** The provider refused this message for a non-token, non-transient reason. Log; no retry. */
    case Rejected = 'rejected';
}
