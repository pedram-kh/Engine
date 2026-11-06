<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Services\MessageSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * AH-119 B3 — `GET agencies/{agency}/message-search?q=&cursor=`. Any active member may search (staff
 * included: the same read gate as the inbox; no per-brand ACL exists). The flag is enforced by the
 * `RequireMessagesV2EditSearch` route middleware (404 while off). See {@see MessageSearch}.
 */
final class AgencyMessageSearchController
{
    public function __construct(private readonly MessageSearch $search) {}

    public function index(Request $request, Agency $agency): JsonResponse
    {
        Gate::authorize('viewAny', AgencyCreatorRelation::class);

        $request->merge(['q' => is_string($request->query('q')) ? trim((string) $request->query('q')) : $request->query('q')]);
        $params = $request->validate([
            'q' => ['required', 'string', 'min:'.MessageSearch::MIN_TERM_LENGTH, 'max:200'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);

        /** @var User $viewer */
        $viewer = $request->user();
        $result = $this->search->run($agency, $viewer, (string) $params['q'], is_string($params['cursor'] ?? null) ? $params['cursor'] : null);

        return response()->json(['data' => $result['rows'], 'meta' => $result['meta']]);
    }
}
