<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Support;

/**
 * Curated definition of the agency dashboard activity feed
 * (Sprint 4 Chunk 1, 1c; D-c1-8).
 *
 * Feed scoping v1 = agency-stamped rows + action allowlist. The endpoint
 * reads `audit_logs` where `agency_id = {agency}` AND
 * `action ∈ ACTION_ALLOWLIST`. This establishes subject-relevance scoping
 * from day one via the mechanism (stamped rows + a curated allowlist) — not
 * by enriching tenant-less creator/wizard events (`agency_id = null`), which
 * is deferred (see docs/tech-debt.md).
 *
 * The allowlist is pinned by an architecture/source test
 * (`DashboardActivityAllowlistTest`) so adding or removing an action from
 * the feed is a deliberate, reviewed change (§5.1 / §5.15).
 *
 * Curation rationale (the exclusions are as deliberate as the inclusions):
 * the feed favours LIFECYCLE events over field-level CHURN. Including
 * `agency_creator_relation.updated` (auto-emitted on, e.g., an
 * internal-rating tweak) would flood the feed with low-signal noise; we
 * include `agency_creator_relation.created` (roster additions) but not
 * `.updated` / `.deleted`. Likewise `brand.created` / `.archived` /
 * `.restored` are lifecycle moments but `brand.updated` is field churn.
 * `bulk_invite.started` / `.failed` are progress/error-channel noise — the
 * single `bulk_invite.completed` carries the signal. `invitation.created` /
 * `.accepted` are agency-team lifecycle; `invitation.expired_on_attempt` is
 * an edge event left out. Creator wizard events stamp `agency_id = null`
 * and are excluded by the stamping mechanism (deferred enrichment).
 */
final class DashboardActivityFeed
{
    /** Newest-first cap on the number of rows returned (D-c1-8). */
    public const int FEED_LIMIT = 15;

    /**
     * Curated, agency-relevant lifecycle actions. Only rows whose `action`
     * is in this set reach the feed. Values are the `AuditAction` backing
     * strings.
     *
     * @var list<string>
     */
    public const array ACTION_ALLOWLIST = [
        'creator.invited',
        'bulk_invite.completed',
        'agency_creator_relation.created',
        'brand.created',
        'brand.archived',
        'brand.restored',
        'invitation.created',
        'invitation.accepted',
        'agency_settings.updated',
    ];

    /**
     * Per-action WHITELIST of safe metadata keys (PII-safe by construction:
     * we expose only the named keys, never the raw blob — so a future
     * emitter that adds a sensitive key to an allowed action's metadata
     * cannot leak it without a deliberate whitelist edit).
     *
     * Only `bulk_invite.completed` carries non-PII summary counts; every
     * other allowed action carries no render-relevant metadata (the
     * Audited-trait actions use before/after, which the feed never exposes),
     * so they map to an empty set and render a generic per-action template.
     *
     * @var array<string, list<string>>
     */
    public const array METADATA_WHITELIST = [
        'bulk_invite.completed' => ['invited', 'already_invited', 'failed'],
    ];

    /**
     * Reduce a row's raw metadata to the per-action safe subset. Any key not
     * explicitly whitelisted for the action is dropped.
     *
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>
     */
    public static function safeMetadata(string $action, ?array $metadata): array
    {
        if ($metadata === null) {
            return [];
        }

        $allowed = self::METADATA_WHITELIST[$action] ?? [];

        return array_intersect_key($metadata, array_flip($allowed));
    }
}
