<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Erase;

use App\Modules\Erasure\Queue\ErasureRunStatus;
use RuntimeException;

/**
 * The account is already claimed and its run is queued or running: a second click claims nothing
 * and queues nothing. Carries the run's status so the page can say where it stands.
 */
final class EraseInProgress extends RuntimeException
{
    public function __construct(public readonly ErasureRunStatus $status)
    {
        parent::__construct('An erasure is already in progress for this account ('.$status->state.').');
    }
}
