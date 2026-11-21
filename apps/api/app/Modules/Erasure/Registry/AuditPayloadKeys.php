<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Registry;

/**
 * Every literal key an audit call site (or an `Audited` model's allowlist)
 * writes into `metadata`, `before` or `after`, and what erasure does about it
 * (PII map §4.1, plan §4.7).
 *
 * `AuditPayloadKeysGuardTest` runs the Stage 0 parser over `app/` and fails when
 * a call site writes a key that is in none of the three lists, or a payload
 * whose keys cannot be read statically from a file not listed in
 * {@see self::dynamicPayloadFiles()}. So `metadata: ['email' => …]` added next
 * month fails CI until someone decides it, which is what lets AH-104e later
 * shrink the scrub list instead of guessing it.
 */
final class AuditPayloadKeys
{
    /**
     * Keys the audit scrub REMOVES on the rows the subject owns (map §4.1,
     * including the three Stage 0 amendments accepted at Checkpoint 0).
     *
     * @return array<string, string> key => where and why
     */
    public static function scrubKeys(): array
    {
        return [
            'email' => 'S2/S1/S5: the address (user snapshots, login failures, reset requests).',
            'name' => 'S2: a user snapshot; may equal the email for a prospect user.',
            'last_name' => 'S2: a user snapshot.',
            'last_login_ip' => 'S2: a user snapshot.',
            'suspended_reason' => 'S2: a snapshot copy of a free-text reason (the live column is kept under D-D).',
            'display_name' => 'S3: a creator snapshot.',
            'provider_account_id' => 'S4: a payout-method snapshot (a vendor key to a bank account).',
            'before' => 'S3, `creator.admin.field_updated` metadata only: the old value of whichever creator field an admin edited (Stage 0 amendment).',
            'after' => 'S3, `creator.admin.field_updated` metadata only: the new value of whichever creator field an admin edited (Stage 0 amendment).',
            'welcome_message' => 'S3, `creator.approved` metadata only: free text addressed to the creator (Stage 0 amendment).',
        ];
    }

    /**
     * Keys that look personal and are KEPT, each with the ruling that keeps it.
     *
     * @return array<string, string> key => reason
     */
    public static function keptKeys(): array
    {
        return [
            'note' => 'D-D: `creator.kyc.manually_verified` metadata; an admin\'s decision note, the same class as `reason` (accepted at Checkpoint 0).',
            'reason' => 'D-D: the deciding party\'s record.',
            'rejection_reason' => 'D-D: the platform\'s decision record.',
            'field' => 'The NAME of the creator field an admin edited, not its value.',
            'has_note' => 'A boolean flag, not the note.',
            'tax_form_type' => 'A form category (W-9, W-8BEN), not an identifier.',
            'tax_id_country' => 'A country code; kept on the tax profile too (map §3.2).',
            'tax_profile_complete' => 'A boolean flag.',
            'email_verified_at' => 'A timestamp.',
        ];
    }

    /**
     * Keys that are not personal. Grouped by kind; each group states the reason once.
     *
     * @return array<string, string> key => reason
     */
    public static function notPersonalKeys(): array
    {
        $groups = [
            'An id, ulid or foreign key: points at a row that is anonymised in place, which is the point of pseudonymising.' => 'agency_file_id,agency_id,approved_by_user_id,assignment_id,exclusive_brand_ids,automation_rule_id,blacklisted_by_user_id,brand_id,campaign_id,creator_id,envelope_id,from_column_id,impersonated_user_id,impersonation_ulid,integration_event_id,invited_by_user_id,posted_content_id,provider_event_id,rule_id,to_column_id,user_id,verified_by_user_id',
            'A flag, status or enum value.' => 'application_status,blacklist_scope,blacklist_type,cause,charges_enabled,enabled,error,event_type,fired_by,flag,guard,is_blacklisted,is_default,is_suspended,kyc_method,kyc_status,mfa,mfa_required,mode,origin,payout_status,payouts_enabled,platform,previous_status,provider,relationship_status,role,status,type,verification_level,visible_to_creators,tier,restrict_to_listing_regions,campaign_type',
            'A timestamp.' => 'exclusivity_ends_on,approved_at,blacklisted_at,deleted_at,invitation_expires_at,invitation_sent_at,kyc_verified_at,last_active_at,last_engaged_at,last_login_at,mfa_exempted_at,rejected_at,scheduled_for,submitted_at,suspended_at,verified_at,was_scheduled_for',
            'A count, threshold, window or score.' => 'code_count,columns_removed,columns_seeded,internal_rating,pool_memberships_deleted,profile_completeness_score,remaining_count,revoked,row_count,threshold,window_hours,window_minutes',
            'Money or currency of an assignment, not a person.' => 'agreed_fee_currency,agreed_fee_minor_units,currency,previous_agreed_fee_minor_units',
            'PL-1.2 campaign snapshot written when a wave is created with its campaign (P3): the campaign\'s own settings (objective, budget, posting and listing flags). An agency\'s planning data, no creator is a field of it.' => 'budget_currency,budget_minor_units,creator_posts_content,listed_on_jobs_board,objective',
            'A from/to state or column transition label.' => 'from,to',
            'A user id on each side of an owner reassign (AH-119 A3): an id, not a name.' => 'from_user,to_user',
            'The ulids of a message and its thread (AH-119 B2 edit and delete audit rows): ids, never the text.' => 'message_ulid,thread_ulid',
            'Locale, currency or UI preference.' => 'categories,country_code,preferred_currency,preferred_language,primary_language,theme_preference,timezone',
            'Boolean marker of a completed step.' => 'payout_method_set',
            'PL-1.1 wave attributes (name, go-live date, markets): an agency\'s planning data about a brand, no creator is a field of it. `name` is also decided under scrubKeys() for user snapshots; that decision stands.' => 'go_live_on,markets',
            'PL-1.1 client-file attributes (filename, type, MIME, size): an agency-owned file about a brand. The filename is typed by the uploading agency user; it is not creator data.' => 'file_type,mime_type,original_filename,size_bytes',
            'PL-1.3 wave tracker attributes (stream, step key, owner flag, status source, planned and actual dates): an agency\'s planning data about a wave, no creator is a field of it. A step owner is carried by the already decided `from_user` and `to_user` ids (AH-119 A3), never a name; `owner_client` is a boolean flag; `name` and `status` are decided above.' => 'stream,step_key,owner_client,status_source,starts_on,due_on,completed_on',
            'PL-2.1 strategy workspace attributes (the wave and USP/persona/angle ulids, market codes, a USP colour key, a list of changed FIELD NAMES, a list of ulids in a new order, counts of angles or personas): ids, codes and counts of an agency\'s planning data, no creator is a field of it. The card TEXT and the persona name are never put in a payload.' => 'wave,usp,persona,angle,market,from_market,to_market,color_key,changed,order,angles_removed,personas_copied',
            'PL-2.2 messaging-matrix counts and a flag (matrix cells removed with a USP or persona, whether removing a hand-written angle created the synced one, synced angles skipped by a persona copy; the cell level and the one angle converted by "Use the matrix line"): numbers, a boolean and an enum value of an agency\'s planning data, no creator is a field of it. The rationale and the message line are never put in a payload.' => 'cells_removed,matrix_angle_created,synced_angles_skipped,cells_created,cells_updated,cells_cleared,cells_unchanged,angles_created,angles_updated,kept_manual,levels,changed,level,angles_converted',
            'PL-2.3 concept attributes (concept, image and link ulids, the source concept of a duplicate, a list of changed FIELD NAMES, counts of chips, images, links and decisions removed or copied, concepts sent back to Awaiting by a USP delete, and flags: reset to Awaiting, a note or comment exists): ids, counts and booleans of an agency\'s planning data. The concept text, a link URL or title, and what a client contact said (name, note, comment) are never put in a payload.' => 'concept,from_concept,image,link,fields_changed,chips,chips_removed,images,images_removed,links,links_removed,decisions_removed,concepts_reset,reset_to_awaiting,has_note,has_comment',
            'The erasure run\'s own bookkeeping: its ledger ulid, digest and per-table counts. No person.' => 'audit_rows_scrubbed,counts,digest,files_deleted,ledger_ulid',
        ];

        $out = [];
        foreach ($groups as $reason => $names) {
            foreach (explode(',', $names) as $name) {
                $out[$name] = $reason;
            }
        }

        return $out;
    }

    /**
     * Files with an audit payload whose keys the parser cannot read statically
     * (a variable, a snapshot method, `toArray()`), each with why it is not a
     * creator-PII channel. A NEW file, or a new payload param in a listed file,
     * is not exempted by this list: only the file+param pairs below are.
     *
     * @return array<string, string> "relative/path.php|param" => reason
     */
    public static function dynamicPayloadFiles(): array
    {
        $agencyOwned = 'Snapshots an agency-owned or admin-owned record (brand, pool, campaign, agency settings, file, automation rule); a creator is not a field of it.';
        $p = 'apps/api/app/Modules/';

        $files = [
            'Admin/Http/Controllers/AdminAutomationRuleController.php' => ['after', 'before'],
            'Agencies/Http/Controllers/AgencyFileController.php' => ['after', 'before'],
            'Agencies/Http/Controllers/AgencySettingsController.php' => ['after', 'before'],
            'Brands/Http/Controllers/BrandController.php' => ['after', 'before'],
            'Brands/Http/Controllers/BrandLogoController.php' => ['after', 'before'],
            'Campaigns/Http/Controllers/CampaignController.php' => ['after', 'before'],
            'TalentPools/Http/Controllers/TalentPoolController.php' => ['after', 'before'],
        ];

        $out = [];
        foreach ($files as $file => $params) {
            foreach ($params as $param) {
                $out[$p.$file.'|'.$param] = $agencyOwned;
            }
        }

        $out[$p.'Agencies/Http/Controllers/CreatorBlacklistController.php|metadata'] = 'Built from scope, type and a brand ulid only; the blacklist reason is never passed (see the method docblock).';
        $out[$p.'Creators/Jobs/BulkCreatorInvitationJob.php|metadata'] = 'Run statistics (counts); the typed addresses live in tracked_jobs.result, which is scrubbed separately.';
        $out[$p.'Audit/Concerns/Audited.php|after'] = 'The Audited trait: snapshot is restricted to each model\'s auditableAllowlist(), whose keys the guard reads below.';
        $out[$p.'Audit/Concerns/Audited.php|before'] = 'The Audited trait: snapshot is restricted to each model\'s auditableAllowlist(), whose keys the guard reads below.';
        $out[$p.'Campaigns/Services/CampaignAssignmentStateMachine.php|metadata'] = 'RESIDUAL: array_merge(from/to, caller-supplied $context). The keys callers pass today are fees, draft ids and flags; the parser does not follow them (tech-debt row).';

        ksort($out);

        return $out;
    }

    /**
     * @param  array{keys: array<string, list<string>>, dynamic: list<string>}  $scan  The `--json` output of tools/enumerate-audit-actions.php.
     * @return list<string> one sentence per undecided key or payload
     */
    public static function violations(array $scan): array
    {
        $v = [];
        $decided = self::allDecided();
        foreach ($scan['keys'] as $key => $usedIn) {
            if (! array_key_exists($key, $decided)) {
                $v[] = "Audit payload key `{$key}` (".implode(', ', $usedIn).') is not decided: add it to scrubKeys(), keptKeys() or notPersonalKeys() in app/Modules/Erasure/Registry/AuditPayloadKeys.php.';
            }
        }
        $known = self::dynamicPayloadFiles();
        foreach ($scan['dynamic'] as $site) {
            if (! array_key_exists($site, $known)) {
                $v[] = "Audit payload `{$site}` is not statically readable and not listed: read it, then add the file|param to dynamicPayloadFiles() in app/Modules/Erasure/Registry/AuditPayloadKeys.php with the reason it is not a creator-PII channel.";
            }
        }
        foreach (array_keys(array_intersect_key(self::scrubKeys(), self::keptKeys())) as $both) {
            $v[] = "Key `{$both}` is both scrubbed and kept in AuditPayloadKeys; decide one.";
        }

        return $v;
    }

    /** @return array<string, string> every decided key, whatever the decision */
    public static function allDecided(): array
    {
        return self::scrubKeys() + self::keptKeys() + self::notPersonalKeys();
    }
}
