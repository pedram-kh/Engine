<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

/**
 * Lifecycle of a Creator's application.
 *
 *   incomplete → pending → approved | rejected
 *
 * - incomplete: bootstrap state from sign-up; wizard in progress.
 * - pending:    wizard Step 9 submitted; awaiting platform-admin approval.
 * - approved:   admin has approved (Sprint 4 surface).
 * - rejected:   admin has rejected with rejection_reason (Sprint 4 surface).
 *
 * Stored as varchar(16) on creators.application_status. See
 * docs/03-DATA-MODEL.md §5.
 */
enum ApplicationStatus: string
{
    case Incomplete = 'incomplete';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
