<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Enums;

/**
 * Operational status of a Campaign (Sprint 8 Chunk 1, D-1).
 *
 * Per docs/03-DATA-MODEL.md §7. Stored as varchar(16) on `campaigns.status`.
 *
 *   draft     — being set up; not yet running.
 *   active    — running (creators engaged / being invited).
 *   paused    — temporarily halted.
 *   completed — finished.
 *   cancelled — abandoned.
 *
 * Unlike CampaignAssignmentStateMachine, campaign status has no guarded
 * graph this chunk — it is a settable CRUD field (defaults to `draft`).
 */
enum CampaignStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * A campaign nobody can be engaged on any more (AH-058, D5).
     *
     * `paused` is deliberately NOT terminal: a paused campaign resumes, and
     * auto-rejecting its pending applications would throw away interest the
     * agency is about to want back.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed,
            self::Cancelled => true,
            self::Draft,
            self::Active,
            self::Paused => false,
        };
    }
}
