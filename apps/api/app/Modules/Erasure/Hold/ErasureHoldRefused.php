<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Hold;

use RuntimeException;

/**
 * A hold or a release was refused. The code is a stable, coarse string the endpoint maps to a
 * response; the message names no person.
 */
final class ErasureHoldRefused extends RuntimeException
{
    public const REASON_REQUIRED = 'reason_required';

    public const NOT_A_CREATOR = 'not_a_creator';

    public const ERASED = 'erased';

    public const CLAIMED = 'claimed';

    private function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }

    public static function reasonRequired(): self
    {
        return new self(self::REASON_REQUIRED, 'A reason is required.');
    }

    public static function notACreator(): self
    {
        return new self(self::NOT_A_CREATOR, 'Only a creator account can be held.');
    }

    public static function erased(): self
    {
        return new self(self::ERASED, 'The account is already erased.');
    }

    public static function claimed(): self
    {
        return new self(self::CLAIMED, 'The account is being erased; a hold can no longer be placed.');
    }
}
