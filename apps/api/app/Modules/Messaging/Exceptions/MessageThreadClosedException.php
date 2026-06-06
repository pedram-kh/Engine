<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Exceptions;

use RuntimeException;

/**
 * Thrown when a HUMAN send is attempted on a thread whose assignment is in a
 * send-blocking terminal state — declined / rejected / cancelled (Sprint 11,
 * D-13 + Q2). The thread stays READABLE and SYSTEM messages still write; only
 * human sends are gated. Controllers map this to 422 `message.thread_closed`.
 */
final class MessageThreadClosedException extends RuntimeException
{
    public string $errorCode = 'message.thread_closed';

    public function __construct(string $message = 'This conversation is closed — the assignment is no longer active.')
    {
        parent::__construct($message);
    }
}
