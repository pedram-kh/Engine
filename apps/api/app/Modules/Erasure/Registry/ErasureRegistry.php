<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Registry;

use App\Modules\Erasure\Guards\PaymentPolicy;

/**
 * The approved PII map (docs/reviews/account-deletion-pii-map.md), as code.
 *
 * Every table in the schema is named here, and every table that holds creator
 * data or points at `users` / `creators` is named column by column. That is
 * enforced by {@see RegistryVerifier} in `ErasureRegistrySchemaTest`, which reads
 * the migrated schema and fails on any table, column or foreign key this file
 * does not decide. A new migration therefore cannot add personal data silently:
 * CI stops until someone edits this file and says what erasure does with it.
 *
 * This file only DECIDES. It changes no data. Stages 4a-4g read it to act.
 * The section numbers in the reasons are the map's.
 */
final class ErasureRegistry
{
    /** @var array<string, TableRule>|null */
    private static ?array $tables = null;

    /**
     * The payment policy of every table whose name marks it as financial
     * (see {@see PaymentPolicy}). A new financial
     * table fails the schema spec until it is listed here with a decision.
     *
     * @return array<string, string> table => what erasure does about it
     */
    public static function paymentPolicies(): array
    {
        return [
            'creator_payout_methods' => 'ERASE-ROW, but only after PaymentGuard finds no assignment in a payment state or with a payment id, and VendorGuard finds no real payment provider.',
        ];
    }

    /** @return array<string, TableRule> */
    public static function tables(): array
    {
        return self::$tables ??= self::build();
    }

    /** @return array<string, TableRule> */
    private static function build(): array
    {
        $tables = [
            ...self::identity(),
            ...self::creatorProfile(),
            ...self::agencyHeld(),
            ...self::messaging(),
            ...self::notificationsAuditJobs(),
            ...self::noCreatorData(),
        ];
        ksort($tables);

        return $tables;
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * @param  string  $names  Comma-separated column names.
     * @return array<string, ColumnRule>
     */
    private static function cols(Disposition $d, string $names, string $reason, ?AnonValue $anon = null, ?string $detail = null): array
    {
        $out = [];
        foreach (explode(',', $names) as $name) {
            $out[trim($name)] = new ColumnRule($d, $reason, $anon, $detail);
        }

        return $out;
    }

    /** @return array<string, ColumnRule> */
    private static function keep(string $names, string $reason): array
    {
        return self::cols(Disposition::Keep, $names, $reason);
    }

    /** @return array<string, ColumnRule> */
    private static function anon(string $names, AnonValue $value, string $reason): array
    {
        return self::cols(Disposition::Anon, $names, $reason, $value);
    }

    /** @return array<string, ColumnRule> */
    private static function row(Disposition $d, string $names, string $reason): array
    {
        return self::cols($d, $names, $reason);
    }

    /** @return array<string, ColumnRule> */
    private static function scrub(string $names, string $detail, string $reason): array
    {
        return self::cols(Disposition::ScrubJson, $names, $reason, null, $detail);
    }

    // ── §3.1 identity and authentication ────────────────────────────────────

    /** @return array<string, TableRule> */
    private static function identity(): array
    {
        $pseudonymous = 'Edge, key or timestamp: not identifying once the other columns are gone (§3.1).';

        return [
            'users' => new TableRule(null, 'The subject user row (§3.1). Anchor: never deleted.', [], [
                ...self::anon('email', AnonValue::EmailPlaceholder, 'Frees the address (D-F); a placeholder that can never receive mail.'),
                ...self::anon('name', AnonValue::Erased, 'Direct identifier; may equal the email for a prospect user (§2.1). Never rendered.'),
                ...self::anon('last_name,remember_token,two_factor_secret,two_factor_recovery_codes,two_factor_confirmed_at,last_login_ip', AnonValue::Null, 'Direct identifier or authentication material of a person with no login (§3.1).'),
                ...self::anon('password', AnonValue::RandomPasswordHash, 'Defence in depth; login is also blocked by erased_at.'),
                ...self::anon('erased_at', AnonValue::Now, 'The marker: login block and the exclusion predicate for every fan-out.'),
                ...self::keep('id,ulid,type,email_verified_at,preferred_language,preferred_currency,timezone,theme_preference,last_login_at,created_at,updated_at', 'Not identifying once the rest is gone; ulid is the pseudonymous key audit rows and URLs already hold (§3.1).'),
                ...self::keep('is_suspended,suspended_reason,suspended_at,mfa_required,mfa_exempted_at,mfa_exemption_reason,two_factor_enrollment_suspended_at', 'Platform safety record by the deciding party (D-D).'),
                ...self::keep('deletion_requested_at,deletion_scheduled_for', 'AH-104b-del: when the person asked, and when the grace ended. A timestamp, not identifying once the other columns are gone; the record that an erasure followed a request.'),
                ...self::keep('deleted_at', 'Left null: a soft delete would put creator = null into agency pages. The marker is erased_at.'),
            ]),
            'sessions' => new TableRule(Disposition::EraseRow, 'Live credentials plus IP and UA (§3.1).', [], self::row(Disposition::EraseRow, 'id,user_id,ip_address,user_agent,payload,last_activity', 'Live credentials plus IP and UA.'), 'user_id = subject user'),
            'personal_access_tokens' => new TableRule(Disposition::EraseRow, 'The token is the session (§3.1); explicit because User is SoftDeletes.', [], self::row(Disposition::EraseRow, 'id,tokenable_type,tokenable_id,name,token,abilities,last_used_at,expires_at,created_at,updated_at', 'The token is the session.'), 'tokenable = subject user'),
            'password_reset_tokens' => new TableRule(Disposition::DelMatch, 'Keyed by address with no user id (§3.1).', [], self::row(Disposition::DelMatch, 'email,token,created_at', 'Keyed by address.'), 'email = old email'),
            'user_push_tokens' => new TableRule(Disposition::EraseRow, 'A device identifier (§3.1).', ['user_id'], self::row(Disposition::EraseRow, 'id,user_id,personal_access_token_id,platform,token,app_version,last_seen_at,invalidated_at,invalidation_reason,created_at,updated_at', 'A device identifier.'), 'user_id = subject user'),
            'push_deliveries' => new TableRule(Disposition::EraseRow, 'Delivery telemetry of the creator\'s own device (§3.1).', ['user_id'], self::row(Disposition::EraseRow, 'id,user_id,user_push_token_id,platform,notification_type,notification_ulid,outcome,attempt,final,code,http_status,detail,created_at', 'Telemetry of the creator\'s own device.'), 'user_id = subject user'),
            'user_notification_preferences' => new TableRule(Disposition::EraseRow, 'The creator\'s own settings (§3.1).', ['user_id'], self::row(Disposition::EraseRow, 'id,user_id,type,channel,is_enabled,created_at,updated_at', 'The creator\'s own settings.'), 'user_id = subject user'),
            'features' => new TableRule(Disposition::DelMatch, 'A per-user Pennant override is a row about the person; scope format not verified (§3.1).', [], self::row(Disposition::DelMatch, 'id,name,scope,value,created_at,updated_at', 'Per-user flag override.'), 'scope matches the subject'),
            'cache' => new TableRule(Disposition::Expire, 'TTL\'d; the limiter hashes its key (§3.1). Not verified for the three auth limiters.'),
            'cache_locks' => new TableRule(Disposition::Expire, 'TTL\'d lock keys (§3.1).'),
            'jobs' => new TableRule(Disposition::Expire, 'Transient. The real control is the erased-user exclusion predicate in workers (§3.1).'),
            'job_batches' => new TableRule(Disposition::NotApplicable, 'Counters and ids (§3.1).', [], [], null, [
                'name' => 'Batch label chosen by our code, never a person\'s name.',
            ]),
            'failed_jobs' => new TableRule(Disposition::DelMatch, 'Persists indefinitely; queued mail carries the address (§3.1).', [], self::row(Disposition::DelMatch, 'id,uuid,connection,queue,payload,exception,failed_at', 'payload and exception may embed the address.'), 'payload or exception contains the old email'),
            'erasure_ledger' => new TableRule(null, 'The run log (plan §4.6): pseudonymous ulid, counts and a digest; no personal data by construction.', ['run_by_user_id'], [
                ...self::keep('id,ulid,user_ulid,state,counts,digest,run_by_user_id,started_at,completed_at,created_at,updated_at', $pseudonymous.' Column list pinned by ErasureSchemaTest.'),
            ]),
        ];
    }

    // ── §3.2 the creator's own profile and satellites ───────────────────────

    /** @return array<string, TableRule> */
    private static function creatorProfile(): array
    {
        $skeleton = 'Non-PII skeleton kept until counsel answers the retention question (D-E).';

        return [
            'creators' => new TableRule(null, 'The subject creator row (§3.2). Anchor: never deleted.', ['user_id', 'approved_by_user_id', 'verified_by_user_id'], [
                ...self::anon('display_name,bio,welcome_message,date_of_birth,phone,whatsapp,address_street,address_postal_code,region,content_companions,accent,incomplete_nudge_sent_at', AnonValue::Null, 'Identity, self-authored text or quasi-identifier (§3.2).'),
                ...self::anon('avatar_path,cover_path', AnonValue::Null, 'Image of the person; the S3 objects are deleted in Stage 4f (§5).'),
                ...self::anon('is_discoverable', AnonValue::False, 'Removes the row from discovery and pickers.'),
                ...self::anon('erased_at', AnonValue::Now, 'The marker agency resources read as is_erased.'),
                ...self::keep('country_code,primary_language,secondary_languages,categories,tier,verification_level', 'Coarse, non-identifying once name, bio, avatar and handles are gone (§3.2).'),
                ...self::keep('application_status,approved_at,submitted_at,rejected_at,kyc_status,kyc_method,kyc_verified_at,tax_profile_complete,payout_method_set,profile_completeness_score,last_active_at,click_through_accepted_at', 'Lifecycle facts and consent evidence (§3.2).'),
                ...self::keep('rejection_reason', 'The platform\'s decision record (D-D).'),
                ...self::keep('id,ulid,user_id,approved_by_user_id,verified_by_user_id,signed_master_contract_id,created_at,updated_at,deleted_at', 'Edges and the pseudonymous key; the RESTRICT edges stay valid (§3.2).'),
            ]),
            'creator_tax_profiles' => new TableRule(null, 'Highest-value PII on the system (§3.2, D-E): the identifying columns go, the skeleton stays.', ['creator_id', 'verified_by_user_id'], [
                ...self::anon('legal_name,tax_id', AnonValue::EncryptedErased, 'NOT NULL encrypted: real ciphertext of the sentinel, never a plain string.'),
                ...self::anon('address', AnonValue::EncryptedEmptyArray, 'NOT NULL encrypted:array: ciphertext of [].'),
                ...self::keep('tax_id_country', 'NOT NULL, a country code, not identifying alone; kept (map §3.2).'),
                ...self::keep('id,ulid,creator_id,tax_form_type,submitted_at,verified_at,verified_by_user_id,created_at,updated_at', $skeleton),
            ]),
            'creator_payout_methods' => new TableRule(Disposition::EraseRow, 'A vendor key that points at a bank account (§3.2); the vendor-side detach runs first (Stage 4g).', ['creator_id'], self::row(Disposition::EraseRow, 'id,ulid,creator_id,provider,provider_account_id,currency,is_default,status,verified_at,created_at,updated_at', 'Creator-owned (D-A).'), 'creator_id = subject creator'),
            'creator_kyc_verifications' => new TableRule(null, 'decision_data is the whole identity-verification response (§3.2, D-E).', ['creator_id'], [
                ...self::anon('decision_data,failure_reason,provider_session_id,provider_decision_id', AnonValue::Null, 'Identity evidence and vendor ids; vendor ids go only after the vendor-side delete.'),
                ...self::keep('id,ulid,creator_id,provider,status,started_at,completed_at,expires_at,created_at,updated_at', $skeleton),
            ]),
            'creator_social_accounts' => new TableRule(Disposition::EraseRow, 'Handles identify; tokens are live credentials at a third party (§3.2).', ['creator_id'], self::row(Disposition::EraseRow, 'id,ulid,creator_id,platform,platform_user_id,handle,profile_url,oauth_access_token,oauth_refresh_token,oauth_expires_at,last_synced_at,sync_status,metrics,audience_demographics,is_primary,created_at,updated_at,deleted_at', 'Creator-owned (D-A).'), 'creator_id = subject creator'),
            'creator_portfolio_items' => new TableRule(Disposition::EraseRow, 'The creator\'s own work and face (§3.2); S3 objects deleted in Stage 4f.', ['creator_id'], self::row(Disposition::EraseRow, 'id,ulid,creator_id,kind,title,description,s3_path,external_url,thumbnail_path,mime_type,size_bytes,duration_seconds,position,created_at,updated_at,deleted_at,processing_status', 'Creator-owned (D-A).'), 'creator_id = subject creator'),
            'creator_availability_blocks' => new TableRule(Disposition::EraseRow, 'Personal schedule (§3.2).', ['creator_id'], self::row(Disposition::EraseRow, 'id,ulid,creator_id,starts_at,ends_at,is_all_day,kind,block_type,reason,assignment_id,is_recurring,recurrence_rule,external_calendar_id,external_event_id,created_at,updated_at', 'Creator-owned (D-A).'), 'creator_id = subject creator'),
        ];
    }

    // ── §3.3 records an agency holds (anchors: never deleted) ───────────────

    /** @return array<string, TableRule> */
    private static function agencyHeld(): array
    {
        $anchor = 'Anchor: the agency\'s commercial record; never deleted (D-A).';

        return [
            'campaign_assignments' => new TableRule(null, 'The agency\'s commercial anchor and, later, the payment anchor (§3.3).', ['creator_id', 'invited_by_user_id', 'cancelled_by_user_id'], [
                ...self::keep('id,ulid,agency_id,campaign_id,brand_id,creator_id,status,invited_at,invited_by_user_id,responded_at,accepted_at,contract_id,agreed_fee_minor_units,agreed_fee_currency,countered_fee_minor_units,countered_fee_currency,markup_minor_units,total_charged_to_brand_minor_units,deliverables,posting_due_at,submitted_draft_at,approved_at,posted_at,verified_live_at,payment_id,cancelled_at,cancelled_by_user_id,created_at,updated_at,deleted_at,draft_due_at,posting_overdue_flagged_at,draft_overdue_flagged_at,fee_per,previously_declined', $anchor),
                ...self::keep('cancelled_reason,notes,offer_description,offer_attachment_path,offer_attachment_name,offer_attachment_mime,offer_attachment_size_bytes', 'Agency-authored decision record or terms (D-D).'),
            ]),
            'campaign_applications' => new TableRule(null, 'The decision record survives; the creator-authored pitch does not (D-C).', ['creator_id'], [
                ...self::anon('note', AnonValue::Null, 'Creator-authored free text with no resulting record (D-C).'),
                ...self::keep('id,ulid,agency_id,campaign_id,creator_id,status,responded_at,created_at,updated_at', $anchor),
            ]),
            'campaign_drafts' => new TableRule(null, 'The agency approved it and may hold usage rights (§3.3, D-C); media decision deferred.', ['submitted_by_creator_id', 'reviewed_by_user_id', 'client_reviewed_by_user_id'], [
                ...self::keep('id,ulid,assignment_id,version,submitted_by_creator_id,submitted_at,caption,hashtags,mentions,links,media_attachments,review_status,reviewed_at,reviewed_by_user_id,client_review_status,client_reviewed_at,client_reviewed_by_user_id,ai_qc_results,ai_qc_passed,created_at,updated_at', 'Approved work product (D-C); media under creators/{ulid}/drafts/ kept pending the D-C ruling.'),
                ...self::keep('review_feedback,client_review_feedback', 'The agency\'s own feedback (D-D).'),
            ]),
            'campaign_posted_content' => new TableRule(null, 'Public by nature; the agency\'s proof of delivery (D-G).', [], [
                ...self::keep('id,ulid,assignment_id,platform,post_url,platform_post_id,posted_at,verified_at,verification_status,last_metrics_synced_at,metrics,metrics_history,created_at,updated_at', 'Proof of delivery; the post is public on a third party\'s platform (D-G).'),
            ]),
            'contracts' => new TableRule(null, 'A signed agreement is a legal record; flagged for counsel (§3.3).', ['created_by_user_id', 'signed_by_creator_id'], [
                ...self::keep('id,ulid,agency_id,kind,subject_type,subject_id,template_id,version,title,body_markdown,body_pdf_path,signature_provider,signature_envelope_id,status,sent_at,signed_at,signed_by_creator_id,signed_signature_data,expires_at,created_by_user_id,created_at,updated_at,deleted_at', 'Legal record; the signing evidence makes it enforceable (§3.3).'),
            ]),
            'agency_creator_relations' => new TableRule(null, 'A live invite token for an erased user is an account-claim hazard (§3.3).', ['creator_id', 'blacklisted_by_user_id', 'invited_by_user_id'], [
                ...self::anon('invitation_token_hash,invitation_expires_at', AnonValue::Null, 'SignUpService resolves the user from this hash.'),
                ...self::keep('id,ulid,agency_id,creator_id,relationship_status,is_blacklisted,blacklist_scope,blacklist_type,blacklisted_at,blacklisted_by_user_id,notification_sent_at,appeal_status,appeal_submitted_at,total_campaigns_completed,total_paid_minor_units,last_engaged_at,invitation_sent_at,invited_by_user_id,created_at,updated_at', $anchor),
                ...self::keep('blacklist_reason,internal_rating,internal_notes', 'The agency\'s own record (D-D).'),
            ]),
            'brand_creator_blacklists' => new TableRule(null, 'An agency\'s ban is its record (D-D).', ['creator_id', 'blacklisted_by_user_id'], [
                ...self::keep('id,ulid,brand_id,creator_id,blacklist_type,reason,blacklisted_at,blacklisted_by_user_id,notification_sent_at,created_at,updated_at,deleted_at', 'The agency\'s record (D-D).'),
            ]),
            'talent_pool_creators' => new TableRule(Disposition::EraseRow, 'A pure pivot; keeping it puts a ghost in a curated list (§3.3).', ['creator_id', 'added_by_user_id'], self::row(Disposition::EraseRow, 'id,talent_pool_id,creator_id,added_by_user_id,created_at,updated_at', 'Pure pivot (D-A).'), 'creator_id = subject creator'),
            'campaign_job_notifications' => new TableRule(Disposition::EraseRow, 'System dedupe ledger; no other party\'s claim (§3.3).', ['creator_id'], self::row(Disposition::EraseRow, 'id,campaign_id,creator_id,notified_at', 'System dedupe ledger.'), 'creator_id = subject creator'),
            'board_cards' => new TableRule(null, 'Edge to an anchor; no creator data (§3.3).', [], [
                ...self::keep('id,ulid,board_id,column_id,agency_id,assignment_id,position,created_at,updated_at', 'Edge to an anchor.'),
            ]),
            'board_card_movements' => new TableRule(null, 'Id only; reason is system or agency text (§3.3).', ['triggered_by_user_id'], [
                ...self::keep('id,card_id,from_column_id,to_column_id,triggered_by,triggered_event_key,triggered_by_user_id,reason,created_at', 'Id only; the reason is system or agency text.'),
            ]),
        ];
    }

    // ── §3.4 messaging ──────────────────────────────────────────────────────

    /** @return array<string, TableRule> */
    private static function messaging(): array
    {
        $attachments = 'Creator-authored uploads only (the creator\'s own messages); the S3 objects are deleted per object in Stage 4f, never by shared prefix.';
        $conversation = 'The agency\'s conversation record; body kept (D-C).';

        return [
            'messages' => new TableRule(null, 'Body kept (D-C); the creator\'s attachments scrubbed (§3.4).', ['sender_user_id'], [
                ...self::scrub('attachments', 'attachments of messages whose sender_user_id is the subject user: set to null', $attachments),
                ...self::keep('id,ulid,thread_id,sender_user_id,sender_role,kind,body,system_event_key,created_at,updated_at,deleted_at', $conversation),
            ]),
            'relationship_messages' => new TableRule(null, 'Body kept (D-C); the creator\'s attachments scrubbed (§3.4).', ['sender_user_id'], [
                ...self::scrub('attachments', 'attachments of messages whose sender_user_id is the subject user: set to null', $attachments),
                ...self::keep('id,ulid,thread_id,sender_user_id,sender_role,kind,body,created_at,updated_at,deleted_at', $conversation),
            ]),
            'message_threads' => new TableRule(null, 'Edge to an anchor; no creator data of its own (§3.4).', [], [
                ...self::keep('id,ulid,agency_id,assignment_id,last_message_at,created_at,updated_at', 'Edge to an anchor.'),
            ]),
            'relationship_threads' => new TableRule(null, 'Edge to an anchor; no creator data of its own (§3.4).', ['creator_id'], [
                ...self::keep('id,ulid,agency_id,creator_id,last_message_at,created_at,updated_at', 'Edge to an anchor.'),
            ]),
            'message_read_receipts' => new TableRule(Disposition::EraseRow, 'The creator\'s own reading behaviour (§3.4).', ['user_id'], self::row(Disposition::EraseRow, 'id,message_id,user_id,read_at', 'The creator\'s own reading behaviour.'), 'user_id = subject user'),
            'relationship_message_read_receipts' => new TableRule(Disposition::EraseRow, 'The creator\'s own reading behaviour (§3.4).', ['user_id'], self::row(Disposition::EraseRow, 'id,message_id,user_id,read_at', 'The creator\'s own reading behaviour.'), 'user_id = subject user'),
            'message_email_debounces' => new TableRule(Disposition::EraseRow, 'Mail-throttle state for a person who will receive no mail (§3.4).', ['recipient_user_id'], self::row(Disposition::EraseRow, 'id,thread_type,thread_id,recipient_user_id,last_emailed_at,created_at,updated_at', 'Mail-throttle state.'), 'recipient_user_id = subject user'),
        ];
    }

    // ── §3.5 / §3.6 notifications, audit, integrations, jobs ────────────────

    /** @return array<string, TableRule> */
    private static function notificationsAuditJobs(): array
    {
        return [
            'notifications' => new TableRule(null, 'Three populations on different people\'s feeds (§3.5).', ['recipient_user_id', 'actor_user_id'], [
                ...self::scrub('data', 'creator_name and counterparty_name where the subject resolves to the creator; sender_name where actor_user_id is the creator', 'Names embedded in the payload (§4.2).'),
                ...self::keep('id,ulid,recipient_user_id,actor_user_id,subject_type,subject_id,type,read_at,created_at', 'Row, ids and type kept on agency members\' feeds.'),
            ], 'ERASE-ROW where recipient_user_id = subject user (the creator\'s own feed); the other two populations are scrubbed, not deleted'),
            'audit_logs' => new TableRule(null, 'Append-only by model, NOT by grant (the app role holds UPDATE in production, read-back C); scrubbed in five columns by DatabaseAuditScrub, rows kept (§3.6, D-B).', [], [
                ...self::anon('ip,user_agent', AnonValue::Null, 'Belong to the ACTOR (§2.3): nulled on S1 rows and on S5 rows no other user acted in.'),
                ...self::scrub('before,after,metadata', 'key list: AuditPayloadKeys::scrubKeys(), on predicates S1-S5 (map §4.1)', 'Snapshot copies of data erased elsewhere.'),
                ...self::keep('id,ulid,agency_id,actor_type,actor_id,actor_role,action,subject_type,subject_id,subject_ulid,created_at,impersonator_user_id', 'Ids and ulids point at an anonymised row, which is the point of pseudonymising (§4.1).'),
                ...self::keep('reason', 'The deciding party\'s record (D-D).'),
            ]),
            'integration_events' => new TableRule(null, 'payload is the raw vendor body (§3.6); production vendor state unknown.', [], [
                ...self::scrub('payload', 'replaced with {"erased": true} for events whose body contains a subject vendor id', 'Raw vendor body.'),
                ...self::keep('id,provider,provider_event_id,event_type,processed_at,received_at', 'Idempotency key and type kept so a vendor retry is still recognised as a duplicate.'),
                ...self::keep('processing_error', 'Our processor\'s error string; NOT verified to exclude vendor-body content (recorded as a residual).'),
            ]),
            'tracked_jobs' => new TableRule(null, 'Bulk-invite failure rows hold the typed address (§3.6).', ['initiator_user_id'], [
                ...self::scrub('result', 'result.failures[].email where email = old email', 'Typed address in a failure row.'),
                ...self::keep('id,ulid,kind,initiator_user_id,agency_id,status,progress,started_at,completed_at,estimated_completion_at,failure_reason,created_at,updated_at', 'initiator_user_id is an agency member: untouched.'),
            ]),
            'admin_impersonation_sessions' => new TableRule(null, 'Security record of an admin action (§3.6): ip and reason are the admin\'s.', ['admin_user_id', 'impersonated_user_id'], [
                ...self::keep('id,ulid,admin_user_id,impersonated_user_id,reason,token_hash,expires_at,started_at,claimed_at,ended_at,ip,created_at', 'The admin\'s record; impersonated_user_id is an id.'),
            ]),
            'integration_credentials' => new TableRule(Disposition::NotApplicable, 'Agency-scoped vendor credentials, not creator data (§3.6).'),
        ];
    }

    // ── §3.7 no creator data ────────────────────────────────────────────────

    /** @return array<string, TableRule> */
    private static function noCreatorData(): array
    {
        $agencyOrg = 'An agency organisation or brand, not a creator.';

        return [
            'agencies' => new TableRule(Disposition::NotApplicable, 'Agency organisation (§3.7).', [], [], null, [
                'name' => $agencyOrg,
                'billing_email' => 'The agency\'s billing contact.',
                'tax_id' => 'The agency\'s own tax id.',
                'tax_id_country' => 'The agency\'s own tax country.',
                'address' => 'The agency\'s address.',
            ]),
            'admin_profiles' => new TableRule(Disposition::NotApplicable, 'Admin people; a creator is never a row here (§3.7).', ['user_id'], [], null, [
                'ip_allowlist' => 'An admin\'s allowed networks.',
            ]),
            'agency_users' => new TableRule(Disposition::NotApplicable, 'Agency members; a creator is never a row here (§3.7).', ['user_id', 'invited_by_user_id']),
            'agency_user_invitations' => new TableRule(Disposition::NotApplicable, 'An agency invite, not a creator\'s (§3.7).', ['invited_by_user_id', 'accepted_by_user_id'], [], null, [
                'email' => 'The invited agency member.',
                'token_hash' => 'An agency-invite token, not a creator\'s.',
            ]),
            'brands' => new TableRule(Disposition::NotApplicable, 'Agency-authored (§3.7).', [], [], null, [
                'name' => $agencyOrg,
            ]),
            'campaigns' => new TableRule(Disposition::NotApplicable, 'Agency-authored; the creator link is a pivot covered above (§3.7).', ['created_by_user_id'], [], null, [
                'name' => 'A campaign title.',
            ]),
            'campaign_attachments' => new TableRule(Disposition::NotApplicable, 'Agency-authored (§3.7).', ['attached_by_user_id']),
            'agency_files' => new TableRule(Disposition::NotApplicable, 'Agency-authored (§3.7).', ['uploaded_by_user_id']),
            'talent_pools' => new TableRule(Disposition::NotApplicable, 'Agency-authored; membership is talent_pool_creators (§3.7).', ['created_by_user_id'], [], null, [
                'name' => 'A pool title.',
            ]),
            'boards' => new TableRule(Disposition::NotApplicable, 'Structure only (§3.7).'),
            'board_columns' => new TableRule(Disposition::NotApplicable, 'Structure only (§3.7).', [], [], null, [
                'name' => 'A column title.',
                'color_token' => 'A design-system colour name, not an auth token.',
            ]),
            'board_automations' => new TableRule(Disposition::NotApplicable, 'Structure only (§3.7).'),
            'admin_automation_rules' => new TableRule(Disposition::NotApplicable, 'Admin configuration (§3.7).', [], [], null, [
                'name' => 'A rule title.',
            ]),
        ];
    }
}
