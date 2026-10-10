<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push;

/**
 * The outcome of one {@see PushSender::send()} plus enough vendor detail to log
 * (`code`, `detail`, `httpStatus`). `detail` is truncated and never contains the
 * device token.
 */
final readonly class PushSendResult
{
    public function __construct(
        public PushOutcome $outcome,
        public ?string $code = null,
        public ?string $detail = null,
        public ?int $httpStatus = null,
    ) {}

    public static function delivered(?int $httpStatus = 200): self
    {
        return new self(PushOutcome::Delivered, null, null, $httpStatus);
    }

    public static function tokenInvalid(string $code, ?string $detail = null, ?int $httpStatus = null): self
    {
        return new self(PushOutcome::TokenInvalid, $code, $detail, $httpStatus);
    }

    public static function retryable(string $code, ?string $detail = null, ?int $httpStatus = null): self
    {
        return new self(PushOutcome::Retryable, $code, $detail, $httpStatus);
    }

    public static function configError(string $code, ?string $detail = null, ?int $httpStatus = null): self
    {
        return new self(PushOutcome::ConfigError, $code, $detail, $httpStatus);
    }

    public static function rejected(string $code, ?string $detail = null, ?int $httpStatus = null): self
    {
        return new self(PushOutcome::Rejected, $code, $detail, $httpStatus);
    }

    public function isDelivered(): bool
    {
        return $this->outcome === PushOutcome::Delivered;
    }
}
