<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use Carbon\CarbonInterface;

final readonly class AccountDeletionResult
{
    /**
     * @param  list<string>  $blockers
     */
    private function __construct(
        public AccountDeletionStatus $status,
        public ?CarbonInterface $requestedAt = null,
        public ?CarbonInterface $scheduledFor = null,
        public array $blockers = [],
        public ?int $retryAfterSeconds = null,
    ) {}

    public static function requested(CarbonInterface $requestedAt, CarbonInterface $scheduledFor): self
    {
        return new self(AccountDeletionStatus::Requested, $requestedAt, $scheduledFor);
    }

    /** A second request while one is open: same answer, the date does not move (C6). */
    public static function alreadyPending(CarbonInterface $requestedAt, CarbonInterface $scheduledFor): self
    {
        return new self(AccountDeletionStatus::AlreadyPending, $requestedAt, $scheduledFor);
    }

    public static function failed(AccountDeletionStatus $status, ?int $retryAfterSeconds = null): self
    {
        return new self($status, retryAfterSeconds: $retryAfterSeconds);
    }

    /** @param list<string> $blockers */
    public static function blocked(array $blockers): self
    {
        return new self(AccountDeletionStatus::Blocked, blockers: $blockers);
    }

    public function succeeded(): bool
    {
        return $this->status === AccountDeletionStatus::Requested
            || $this->status === AccountDeletionStatus::AlreadyPending;
    }
}
