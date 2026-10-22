<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * AH-104b-del (D3, B1): the manifest of every file in an agency-facing module
 * that reads a creator, and what it does about a creator who is scheduled for
 * deletion (pending) or erased.
 *
 * "Hidden" is defined once, on the model (`Creator::scopeVisibleToAgencies()`,
 * `isUnavailableToAgencies()`, `assertAvailableToAgencies()`, `isErased()`).
 * This list is how a NEW file that reads creators cannot forget it:
 * `AgencyCreatorSurfacesTest` fails on any file the heuristic finds that is not
 * here, and on any entry whose file no longer carries the marker its kind
 * promises. The heuristic is a grep for creator-reading shapes and says so; the
 * behavioural matrix (`CreatorUnavailableToAgenciesTest`) is the strong half.
 *
 * Kinds (and the marker the spec requires in the file):
 *   scoped       — a query: `visibleToAgencies(`
 *   guarded      — a NEW action: `assertAvailableToAgencies(`
 *   silent       — a mail/notification fan-out to the creator: `isUnavailableToAgencies(`
 *   resource     — a JSON resource: trait `EmitsErasedState` and the `is_erased` key
 *   flagged      — an inline response: `is_erased` (or `creator_is_erased`)
 *   unchanged    — deliberately NOT changed (history stays visible); a reason is required
 *   via-relation — reaches a creator only through a row the agency already holds, after
 *                  a guarded entry point, and emits nothing new; a reason is required
 *   creator-side — the creator's own surface, not an agency's; a reason is required
 *   model        — a model or factory, not a surface; a reason is required
 */
final class AgencyCreatorSurfaces
{
    public const array MODULES = ['Agencies', 'Campaigns', 'Messaging', 'TalentPools', 'Boards', 'Brands'];

    /** The shapes that mean "this file reads a creator" (a heuristic, and named as one). */
    public const string READS_CREATORS = "/Creators\\\\Models\\\\Creator;|creator:id|display_name|'creator'\\)|->creator\\b/";

    /**
     * Files (relative to app/Modules) that the heuristic would flag, with their kind and reason.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function all(): array
    {
        return [
            // ── Agencies ────────────────────────────────────────────────────
            'Agencies/Concerns/FiltersCreatorColumns.php' => ['via-relation', 'Column filters shared by the roster and discovery; discovery applies the scope in its own base query, the roster keeps history.'],
            'Agencies/Http/Controllers/AgencyConnectionRequestController.php' => ['guarded', 'A NEW connection request to a creator.'],
            'Agencies/Http/Controllers/AgencyCreatorAvailabilityController.php' => ['unchanged', 'The calendar of a rostered creator: history. Erasure deletes the availability blocks themselves.'],
            'Agencies/Http/Controllers/AgencyCreatorController.php' => ['flagged', 'The roster keeps the row of a pending creator (no trace if cancelled) and flags an erased one.'],
            'Agencies/Http/Controllers/AgencyCreatorDetailController.php' => ['unchanged', 'The detail of a rostered creator: history. The resource carries is_erased.'],
            'Agencies/Http/Controllers/AgencyCreatorDiscoveryController.php' => ['scoped', 'The discovery pool and the public profile.'],
            'Agencies/Http/Controllers/CreatorBlacklistController.php' => ['unchanged', 'A safety action on a creator the agency already holds a relation with; it must stay possible.'],
            'Agencies/Http/Controllers/DashboardSummaryController.php' => ['unchanged', 'Counts of application statuses; emits no creator field.'],
            'Agencies/Http/Resources/AgencyCreatorDetailResource.php' => ['resource', 'The roster detail.'],
            'Agencies/Http/Resources/CreatorDiscoveryResource.php' => ['resource', 'The discovery card.'],
            'Agencies/Http/Resources/CreatorPublicProfileResource.php' => ['resource', 'The discovery profile.'],
            'Agencies/Models/AgencyCreatorRelation.php' => ['model', 'The relation model.'],
            'Agencies/Models/BrandCreatorBlacklist.php' => ['model', 'The brand blacklist model.'],
            'Agencies/Support/AgencyCreatorRelationGuard.php' => ['via-relation', 'Resolves the relation row an agency already holds; called after the guarded entry points.'],

            // ── Boards ──────────────────────────────────────────────────────
            'Boards/Http/Controllers/BoardCardController.php' => ['via-relation', 'Loads a card whose creator is emitted through BoardCardResource (is_erased).'],
            'Boards/Http/Controllers/BoardController.php' => ['via-relation', 'Loads the board whose cards emit the creator through BoardCardResource (is_erased).'],
            'Boards/Http/Resources/BoardCardResource.php' => ['resource', 'The board card.'],

            // ── Campaigns ───────────────────────────────────────────────────
            'Campaigns/Http/Controllers/CampaignApplicationController.php' => ['guarded', 'ACCEPTING an application creates an assignment (a new action); rejecting stays open.'],
            'Campaigns/Http/Controllers/CampaignAssignmentContractController.php' => ['unchanged', 'Sends a contract on an EXISTING assignment; existing threads and assignments continue (C4).'],
            'Campaigns/Http/Controllers/CampaignAssignmentController.php' => ['guarded', 'INVITING a creator.'],
            'Campaigns/Http/Controllers/CampaignAssignmentResolutionController.php' => ['guarded', 'AH-104c: a NEW live verification (manual verify) of a pending or erased creator is refused; the other resolutions on an EXISTING assignment stay open (C4).'],
            'Campaigns/Http/Controllers/CampaignAssignmentReviewController.php' => ['flagged', 'The review payload of an existing assignment.'],
            'Campaigns/Http/Controllers/CampaignController.php' => ['flagged', 'The posting-due list names the creator.'],
            'Campaigns/Http/Controllers/CampaignDraftController.php' => ['via-relation', 'Emits the creator through CampaignDraftListItemResource (is_erased).'],
            'Campaigns/Http/Resources/CampaignApplicationListItemResource.php' => ['resource', 'The applications list.'],
            'Campaigns/Http/Resources/CampaignAssignmentResource.php' => ['resource', 'The assignment.'],
            'Campaigns/Http/Resources/CampaignDraftListItemResource.php' => ['resource', 'The drafts list.'],
            'Campaigns/Jobs/VerifyPostedContentJob.php' => ['silent', 'AH-104c: holds back the automatic check for a pending or erased creator (the post stays pending); an ordinary creator is verified as before.'],
            'Campaigns/Listeners/RedispatchPostedContentVerification.php' => ['via-relation', 'AH-104c: when the creator cancels a deletion request, re-runs the checks held back meanwhile on assignments the creator already holds; emits nothing.'],
            'Campaigns/Listeners/SendAssignmentNotifications.php' => ['unchanged', 'Lifecycle notices of EXISTING assignments (C4); the erasure cancellation fan-out names no one.'],
            'Campaigns/Models/CampaignApplication.php' => ['model', 'The application model.'],
            'Campaigns/Models/CampaignAssignment.php' => ['model', 'The assignment model.'],
            'Campaigns/Models/CampaignDraft.php' => ['model', 'The draft model.'],
            'Campaigns/Models/CampaignJobNotification.php' => ['model', 'The once-only stamp model.'],
            'Campaigns/Services/AssignmentInviteGate.php' => ['via-relation', 'The blacklist predicate; called by the invite controller AFTER its availability guard.'],
            'Campaigns/Services/CampaignApplicationDecisionService.php' => ['via-relation', 'Settles an application as a side effect of an accepted invite, which is guarded.'],
            'Campaigns/Services/CampaignApplicationNotifier.php' => ['silent', 'Mail and notices to the creator about an application: silent for a pending or erased creator.'],
            'Campaigns/Services/CampaignInvitationService.php' => ['via-relation', 'Creates the invite; only called by the guarded invite and accept controllers.'],
            'Campaigns/Services/JobPostedFanOutService.php' => ['scoped', 'The job-posted fan-out recipients.'],
            'Campaigns/Services/JobsBoardVisibility.php' => ['creator-side', 'What a creator may see on the jobs board; not an agency surface (a pending creator cannot sign in).'],

            // ── Messaging ───────────────────────────────────────────────────
            'Messaging/Database/Factories/RelationshipThreadFactory.php' => ['model', 'A test factory.'],
            'Messaging/Http/Controllers/AgencyMessageController.php' => ['flagged', 'The campaign-thread list names the creator.'],
            'Messaging/Http/Controllers/AgencyRelationshipMessageController.php' => ['flagged', 'The relationship-thread list names the creator.'],
            'Messaging/Http/Controllers/CreatorMessageController.php' => ['creator-side', 'The creator\'s own inbox.'],
            'Messaging/Http/Controllers/CreatorRelationshipMessageController.php' => ['creator-side', 'The creator\'s own inbox.'],
            'Messaging/Http/Controllers/MessageableContactsController.php' => ['flagged', 'The contact picker names the creator.'],
            'Messaging/Models/RelationshipThread.php' => ['model', 'The thread model.'],
            'Messaging/Services/MessageDigestService.php' => ['scoped', 'The digest mail recipients.'],
            'Messaging/Services/MessageableContactsFinder.php' => ['scoped', 'The contact picker query: an unavailable creator only where a thread already exists.'],
            'Messaging/Services/RelationshipMessageNotifications.php' => ['silent', 'Agency messages to a pending or erased creator: stored, never delivered.'],
            'Messaging/Services/RelationshipMessageService.php' => ['guarded', 'No NEW conversation with a pending or erased creator; an existing thread stays writable.'],
            'Messaging/Services/SendMessageNotifications.php' => ['silent', 'Agency messages to a pending or erased creator: stored, never delivered.'],
            'Messaging/Support/RelationshipSendState.php' => ['unchanged', 'The send-state of an EXISTING thread (closed or open); existing threads stay writable (C4).'],

            // ── TalentPools ─────────────────────────────────────────────────
            'TalentPools/Http/Controllers/CreatorTalentPoolController.php' => ['creator-side', 'The creator\'s own pools view.'],
            'TalentPools/Http/Controllers/TalentPoolMembershipController.php' => ['guarded', 'ADDING a creator to a pool; removing stays open.'],
            'TalentPools/Http/Resources/TalentPoolMemberResource.php' => ['resource', 'The pool member.'],
            'TalentPools/Models/TalentPool.php' => ['model', 'The pool model.'],
            'TalentPools/Models/TalentPoolMembership.php' => ['model', 'The membership model.'],
        ];
    }

    /** The marker each kind promises, as a list of alternatives (any one is enough), or null when only a reason is needed. */
    public static function markers(string $kind): ?array
    {
        return match ($kind) {
            'scoped' => ['visibleToAgencies('],
            'guarded' => ['assertAvailableToAgencies('],
            'silent' => ['isUnavailableToAgencies('],
            'resource' => ['EmitsErasedState'],
            'flagged' => ['is_erased', 'creator_is_erased'],
            default => null,
        };
    }
}
