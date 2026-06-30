<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Services\MessageableContactsFinder;
use App\Modules\Messaging\Support\ContactMediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * AH-012 — the gate-filtered CONTACT pickers behind the WhatsApp-style
 * "new conversation" flow. Both sides list only contacts the messaging gate
 * permits (the set-valued half, via {@see MessageableContactsFinder}), so the
 * picker can never surface a contact the send gate would 403.
 *
 *   GET /agencies/{agency}/messageable-creators   (agency picker — paginated + searched)
 *   GET /creators/me/messageable-agencies         (creator picker — small, unpaginated)
 *
 * Homed in the Messaging module for cohesion with the finder (Q2); the creator
 * route keeps its `creators/me/*` prefix. Neither endpoint provisions a thread —
 * picking a contact opens a transient thread view; the row materializes on the
 * first send / attachment-upload (D1).
 */
final class MessageableContactsController
{
    public function __construct(private readonly MessageableContactsFinder $finder) {}

    /**
     * The AGENCY picker: the creators this agency may currently message,
     * paginated with an optional `?search=` substring on display_name (D6).
     * Tenant-scoped under `tenancy.agency`; `viewAny` mirrors the roster's
     * any-member read floor (belt-and-suspenders on the membership middleware).
     */
    public function creators(Request $request, Agency $agency): JsonResponse
    {
        Gate::authorize('viewAny', AgencyCreatorRelation::class);

        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));
        $page = max(1, (int) $request->integer('page', 1));
        $search = $request->query('search');
        $search = is_string($search) ? trim($search) : null;

        $paginator = $this->finder->creatorsForAgency($agency, $search, $perPage, $page);

        /** @var list<AgencyCreatorRelation> $rows */
        $rows = $paginator->items();

        $data = array_map(static function (AgencyCreatorRelation $relation): array {
            $creator = $relation->creator;

            return [
                'id' => $creator?->ulid,
                'type' => 'messageable_creator',
                'attributes' => [
                    'display_name' => $creator?->display_name,
                    // AH-013 — signed avatar for the picker row (paginated list,
                    // so per-row signing is bounded; null when unset / non-S3).
                    'avatar_url' => ContactMediaUrl::resolve($creator?->avatar_path),
                ],
            ];
        }, $rows);

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * The CREATOR picker: the agencies this creator may currently message. Small
     * by nature (a handful of connected agencies), so unpaginated (D6). Carries
     * the agency `logo_path` for the contact-row avatar (free — already on the
     * inbox payload, D5).
     */
    public function agencies(Request $request): JsonResponse
    {
        $creator = $this->requireCreator($request);

        $agencies = $this->finder->agenciesForCreator($creator);

        return response()->json([
            'data' => $agencies->map(static fn (Agency $agency): array => [
                'id' => $agency->ulid,
                'type' => 'messageable_agency',
                'attributes' => [
                    'name' => $agency->name,
                    'logo_path' => $agency->logo_path,
                    // AH-013 — resolved logo for the picker row (passthrough URL
                    // or signed S3 key).
                    'logo_url' => ContactMediaUrl::resolve($agency->logo_path),
                ],
            ])->all(),
        ]);
    }

    private function requireCreator(Request $request): Creator
    {
        /** @var User $user */
        $user = $request->user();
        $creator = $user->creator;

        if ($creator === null) {
            abort(ErrorResponse::single($request, Response::HTTP_NOT_FOUND, 'creator.not_found', 'No creator profile is associated with this user.'));
        }

        return $creator;
    }
}
