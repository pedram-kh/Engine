<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Campaigns\Listeners\CreateMessageThread;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Messaging\Models\MessageThread;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Provisions the one-per-assignment message thread (Sprint 11, D-3).
 *
 * Idempotency across the three create sites — the invite listener
 * ({@see CreateMessageThread}), the defensive
 * create before a system-message write, and the lazy create on first GET — is
 * backed by the `message_threads.assignment_id` UNIQUE. {@see self::forAssignment()}
 * is race-safe: a concurrent double-create collides on the unique and is
 * caught + re-fetched rather than throwing.
 *
 * The global BelongsToAgency scope is deliberately bypassed here (the named,
 * greppable construct per docs/security/tenancy.md §5). Thread provisioning is
 * idempotent infrastructure keyed on the UNIQUE, and `agency_id` is ALWAYS set
 * explicitly from the already-resolved assignment — there is no cross-tenant
 * read: a caller can only provision a thread for an assignment it has already
 * resolved under its own scope. Bypassing the scope makes the firstOrCreate see
 * the canonical row regardless of the ambient context (none in the queued
 * listener; the creator's no-agency context on the creator surface).
 */
final class MessageThreadService
{
    public function forAssignment(CampaignAssignment $assignment): MessageThread
    {
        try {
            return MessageThread::query()
                ->withoutGlobalScope(BelongsToAgencyScope::class)
                ->firstOrCreate(
                    ['assignment_id' => $assignment->id],
                    ['agency_id' => $assignment->agency_id],
                );
        } catch (UniqueConstraintViolationException) {
            // A concurrent create won the race — re-fetch the canonical row.
            return MessageThread::query()
                ->withoutGlobalScope(BelongsToAgencyScope::class)
                ->where('assignment_id', $assignment->id)
                ->firstOrFail();
        }
    }
}
