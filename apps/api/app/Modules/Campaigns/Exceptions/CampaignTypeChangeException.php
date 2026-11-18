<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Exceptions;

use LogicException;

/**
 * PL-1.2 — thrown when code tries to change the type of a saved campaign (normal / for_wave). The user-facing answer is
 * the controller's 422 `campaign.type_immutable`; this is the model-level backstop for any other caller.
 */
final class CampaignTypeChangeException extends LogicException
{
    public function __construct()
    {
        parent::__construct('A saved campaign cannot change type.');
    }
}
