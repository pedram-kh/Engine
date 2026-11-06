<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Exceptions;

use App\Modules\Messaging\Services\MessageRevisionService;
use RuntimeException;

/**
 * AH-119 B2 — a refused edit or delete. `errorCode` is the wire code (422) or `not_found` (404: not the
 * sender's message, not in this thread, a system line, or gone). Raised by
 * {@see MessageRevisionService}; the controllers map it to the envelope.
 */
final class MessageRevisionException extends RuntimeException
{
    public const EDIT_WINDOW_CLOSED = 'message.edit_window_closed';

    public const EDIT_THREAD_CLOSED = 'message.edit_thread_closed';

    public const NOT_EDITABLE = 'message.not_editable';

    public const ALREADY_DELETED = 'message.already_deleted';

    public const NOT_FOUND = 'not_found';

    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->errorCode === self::NOT_FOUND ? 404 : 422;
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 'Not found.');
    }
}
