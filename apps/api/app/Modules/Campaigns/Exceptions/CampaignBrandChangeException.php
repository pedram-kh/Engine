<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Exceptions;

use LogicException;

/**
 * PL-1.2 (P8) — thrown when code tries to change the brand of a saved campaign. A programming error, not a user
 * error: no request can reach it (`UpdateCampaignRequest` carries no `brand_id`).
 */
final class CampaignBrandChangeException extends LogicException
{
    public function __construct()
    {
        parent::__construct('A saved campaign cannot change brand.');
    }
}
