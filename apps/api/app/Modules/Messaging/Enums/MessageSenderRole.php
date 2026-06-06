<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Enums;

/**
 * Who authored a message (Sprint 11, D-15). Stored as varchar(16) on
 * `messages.sender_role`. Per docs/03-DATA-MODEL.md §11.
 *
 * `system` is the only role with NO human sender — a system message has
 * `sender_user_id = null` (D-2), `sender_role = system`, `kind = system`. The
 * human roles (`creator`, `agency_user`, `brand_user`, `admin`) always carry a
 * non-null `sender_user_id`.
 *
 * Catalogue-tripwire pinned in MessageEnumsTest (the CampaignEnumsTest /
 * NotificationTypeEnumTest precedent): adding or removing a case is a
 * deliberate, reviewed edit, never an accidental drift.
 */
enum MessageSenderRole: string
{
    case Creator = 'creator';
    case AgencyUser = 'agency_user';
    case BrandUser = 'brand_user';
    case System = 'system';
    case Admin = 'admin';
}
