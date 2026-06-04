<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Controllers;

use App\Modules\Agencies\Enums\BlacklistScope;
use App\Modules\Agencies\Enums\BlacklistType;
use App\Modules\Agencies\Http\Requests\BlacklistCreatorRequest;
use App\Modules\Agencies\Http\Requests\UnblacklistCreatorRequest;
use App\Modules\Agencies\Mail\CreatorBlacklistedMail;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Agencies\Models\BrandCreatorBlacklist;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Brands\Models\Brand;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * POST   /api/v1/agencies/{agency}/creators/{creator}/blacklist  — blacklist
 * DELETE /api/v1/agencies/{agency}/creators/{creator}/blacklist  — un-blacklist
 *   — Sprint 7 (Part A: A2/A3/A5/A6). The agency's blacklist write surface.
 *
 * A DEDICATED endpoint, deliberately NOT the rating/notes PATCH: D-2 forbids a
 * dual-write, and UpdateAgencyCreatorRelationRequest already scope-guards
 * blacklist fields out of that path. Sits in the same `agencies/{agency}`
 * tenancy stack (auth:web → tenancy.agency → tenancy); a non-member 404s
 * before this runs. Admin/manager only (the `blacklist` ability — staff 403).
 *
 * TWO write paths (D-1/D-2), chosen by `scope`:
 *
 *   agency → columns ON the relation (the six built in Sprint 3): is_blacklisted,
 *            blacklist_scope='agency', blacklist_type, blacklist_reason,
 *            blacklisted_at, blacklisted_by_user_id. Requires an existing
 *            relation — an agency-wide blacklist IS columns on it; a
 *            discovered-but-unconnected creator (no relation) yields a typed
 *            422 (plan-pause decision: restrict, do NOT invent a synthetic
 *            relationship_status). The trait auto-emits a REDACTED
 *            agency_creator_relation.updated (blacklist_reason is not
 *            allowlisted).
 *   brand  → a brand_creator_blacklists row (D-2: does NOT touch the relation).
 *            No relation required. The brand is resolved through the
 *            agency-scoped Brand model (tenant isolation). The trait auto-emits
 *            brand_creator_blacklist.created (reason not allowlisted).
 *
 * Every blacklist also emits the dedicated, REDACTED `creator.blacklisted`
 * verb (D-5): the FACT + actor + scope/type in metadata, never the reason.
 *
 * Notification (D-4): when the agency's `blacklist_notification_policy` setting
 * is on, a queued, locale-aware CreatorBlacklistedMail is sent and
 * notification_sent_at is stamped. Default OFF; the email is generic (no
 * reason, no scope/type detail).
 */
final class CreatorBlacklistController
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly MailFactory $mail,
    ) {}

    public function store(BlacklistCreatorRequest $request, Agency $agency, Creator $creator): JsonResponse
    {
        Gate::authorize('blacklist', AgencyCreatorRelation::class);

        /** @var User $actor */
        $actor = $request->user();
        $scope = BlacklistScope::from($request->string('scope')->value());
        $type = BlacklistType::from($request->string('type')->value());
        $reason = $request->string('reason')->value();

        return $scope === BlacklistScope::Agency
            ? $this->blacklistAgencyWide($agency, $creator, $actor, $type, $reason)
            : $this->blacklistForBrand($request->string('brand_id')->value(), $creator, $actor, $type, $reason);
    }

    public function destroy(UnblacklistCreatorRequest $request, Agency $agency, Creator $creator): JsonResponse
    {
        Gate::authorize('blacklist', AgencyCreatorRelation::class);

        $scope = BlacklistScope::from($request->string('scope')->value());

        return $scope === BlacklistScope::Agency
            ? $this->unblacklistAgencyWide($agency, $creator)
            : $this->unblacklistForBrand($request->string('brand_id')->value(), $creator);
    }

    // ── Agency-wide (A2) ────────────────────────────────────────────────────

    private function blacklistAgencyWide(
        Agency $agency,
        Creator $creator,
        User $actor,
        BlacklistType $type,
        string $reason,
    ): JsonResponse {
        $relation = $this->existingRelation($agency, $creator);

        // An agency-wide blacklist IS columns on the relation, so the relation
        // must exist. No relation → typed 422 (plan-pause: restrict; do not
        // invent a relationship_status to seed a synthetic relation).
        if ($relation === null) {
            return $this->error(
                'blacklist.relation_required',
                'An agency-wide blacklist requires an existing relation with this creator.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        DB::transaction(function () use ($relation, $agency, $creator, $actor, $type, $reason): void {
            $relation->is_blacklisted = true;
            $relation->blacklist_scope = BlacklistScope::Agency;
            $relation->blacklist_type = $type;
            $relation->blacklist_reason = $reason;
            $relation->blacklisted_at = now();
            $relation->blacklisted_by_user_id = $actor->id;
            // Stamp the notification timestamp inside the same write when the
            // policy is on (so the relation reflects "notified") before the
            // queued mail goes out.
            if ($this->notifyEnabled($agency)) {
                $relation->notification_sent_at = now();
            }
            $relation->save();

            // The named, redacted blacklist verb (D-5) — subject is the creator,
            // metadata carries scope/type only, never the reason.
            $this->logBlacklisted($creator, BlacklistScope::Agency, $type);
        });

        if ($this->notifyEnabled($agency)) {
            $this->sendNotification($creator, $agency);
        }

        return $this->success('blacklist.agency.applied', [
            'scope' => BlacklistScope::Agency->value,
            'type' => $type->value,
            'is_blacklisted' => true,
        ]);
    }

    private function unblacklistAgencyWide(Agency $agency, Creator $creator): JsonResponse
    {
        $relation = $this->existingRelation($agency, $creator);

        if ($relation === null || ! $relation->is_blacklisted) {
            return $this->error(
                'blacklist.not_blacklisted',
                'This creator is not agency-wide blacklisted.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Clear ALL blacklist columns (the trait emits a redacted
        // agency_creator_relation.updated; blacklist_reason is not allowlisted).
        $relation->is_blacklisted = false;
        $relation->blacklist_scope = null;
        $relation->blacklist_type = null;
        $relation->blacklist_reason = null;
        $relation->blacklisted_at = null;
        $relation->blacklisted_by_user_id = null;
        $relation->notification_sent_at = null;
        $relation->save();

        return $this->success('blacklist.agency.lifted', [
            'scope' => BlacklistScope::Agency->value,
            'is_blacklisted' => false,
        ]);
    }

    // ── Brand-scoped (A3) ─────────────────────────────────────────────────────

    private function blacklistForBrand(
        string $brandUlid,
        Creator $creator,
        User $actor,
        BlacklistType $type,
        string $reason,
    ): JsonResponse {
        $brand = $this->resolveBrand($brandUlid);
        if ($brand === null) {
            return $this->error(
                'blacklist.brand_not_found',
                'The brand was not found for this agency.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Idempotent: an existing ACTIVE row for (brand, creator) surfaces
        // rather than violating the partial unique index.
        $existing = BrandCreatorBlacklist::query()
            ->where('brand_id', $brand->id)
            ->where('creator_id', $creator->id)
            ->first();

        if ($existing !== null) {
            return $this->success('blacklist.brand.already_blacklisted', [
                'id' => $existing->ulid,
                'scope' => BlacklistScope::Brand->value,
                'type' => $existing->blacklist_type->value,
                'brand_id' => $brand->ulid,
            ]);
        }

        $notify = $this->brandNotifyEnabled($brand);

        $row = DB::transaction(function () use ($brand, $creator, $actor, $type, $reason, $notify): BrandCreatorBlacklist {
            $row = BrandCreatorBlacklist::query()->create([
                'brand_id' => $brand->id,
                'creator_id' => $creator->id,
                'blacklist_type' => $type,
                'reason' => $reason,
                'blacklisted_at' => now(),
                'blacklisted_by_user_id' => $actor->id,
                'notification_sent_at' => $notify ? now() : null,
            ]);

            $this->logBlacklisted($creator, BlacklistScope::Brand, $type, $brand->ulid);

            return $row;
        });

        if ($notify) {
            $this->sendNotification($creator, $brand->agency);
        }

        return $this->success('blacklist.brand.applied', [
            'id' => $row->ulid,
            'scope' => BlacklistScope::Brand->value,
            'type' => $type->value,
            'brand_id' => $brand->ulid,
        ], Response::HTTP_CREATED);
    }

    private function unblacklistForBrand(string $brandUlid, Creator $creator): JsonResponse
    {
        $brand = $this->resolveBrand($brandUlid);
        if ($brand === null) {
            return $this->error(
                'blacklist.brand_not_found',
                'The brand was not found for this agency.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $row = BrandCreatorBlacklist::query()
            ->where('brand_id', $brand->id)
            ->where('creator_id', $creator->id)
            ->first();

        if ($row === null) {
            return $this->error(
                'blacklist.not_blacklisted',
                'This creator is not blacklisted for this brand.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Un-blacklist = soft-delete (D-3) — the trait emits
        // brand_creator_blacklist.deleted; history is preserved.
        $row->delete();

        return $this->success('blacklist.brand.lifted', [
            'scope' => BlacklistScope::Brand->value,
            'brand_id' => $brand->ulid,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function existingRelation(Agency $agency, Creator $creator): ?AgencyCreatorRelation
    {
        return AgencyCreatorRelation::query()
            ->where('agency_id', $agency->id)
            ->where('creator_id', $creator->id)
            ->first();
    }

    /**
     * Resolve a brand by ULID WITHIN the calling agency. Brand carries the
     * BelongsToAgency global scope, so under the route's tenancy context a
     * cross-agency brand_id simply does not resolve — the tenant-isolation
     * boundary for the brand-scoped write path (the table has no agency_id).
     */
    private function resolveBrand(string $brandUlid): ?Brand
    {
        return Brand::query()->where('ulid', $brandUlid)->first();
    }

    private function notifyEnabled(Agency $agency): bool
    {
        return (bool) ($agency->settings['blacklist_notification_policy'] ?? false);
    }

    private function brandNotifyEnabled(Brand $brand): bool
    {
        return $this->notifyEnabled($brand->agency);
    }

    /**
     * The dedicated `creator.blacklisted` verb (D-5). Reason content is NEVER
     * passed — metadata carries scope/type (+ brand ulid for brand scope) only.
     */
    private function logBlacklisted(
        Creator $creator,
        BlacklistScope $scope,
        BlacklistType $type,
        ?string $brandUlid = null,
    ): void {
        $metadata = [
            'scope' => $scope->value,
            'type' => $type->value,
        ];
        if ($brandUlid !== null) {
            $metadata['brand_id'] = $brandUlid;
        }

        $this->auditLogger->log(
            action: AuditAction::CreatorBlacklisted,
            subject: $creator,
            metadata: $metadata,
        );
    }

    /**
     * Queue the creator's blacklist notification (D-4), localized to their
     * preferred language. Mirrors {@see AgencyConnectionRequestController::sendNotification}:
     * defensive on a missing email so a notify-on policy never 500s the write
     * that already succeeded. Generic content — no reason, no scope/type.
     */
    private function sendNotification(Creator $creator, Agency $agency): void
    {
        $user = $creator->user;
        if ($user === null || $user->email === '') {
            return;
        }

        $this->mail
            ->mailer()
            ->to($user->email)
            ->locale($user->preferred_language ?: 'en')
            ->queue(new CreatorBlacklistedMail(
                creatorDisplayName: $creator->display_name ?? '',
                agencyName: $agency->name,
            ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function success(string $code, array $attributes, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json([
            'data' => [
                'type' => 'creator_blacklist',
                'attributes' => $attributes,
            ],
            'meta' => ['code' => $code],
        ], $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'errors' => ['blacklist' => [$message]],
            'meta' => ['code' => $code],
        ], $status);
    }
}
