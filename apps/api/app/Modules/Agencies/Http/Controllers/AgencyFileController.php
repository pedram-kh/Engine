<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Core\Storage\StorageDeleteFailedException;
use App\Modules\Agencies\Enums\AgencyFileCategory;
use App\Modules\Agencies\Http\Resources\AgencyFileOptionResource;
use App\Modules\Agencies\Http\Resources\AgencyFileResource;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyFile;
use App\Modules\Agencies\Services\AgencyFileUploadService;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Agency Files library (AH-091, chunk A):
 *
 *   GET    …/files                index (paginated, newest-first)
 *   POST   …/files/uploads/init     presigned PUT init
 *   POST   …/files/uploads/complete presigned verify + row create (201)
 *   GET    …/files/{file}         show — a FRESH signed download URL
 *   DELETE …/files/{file}         object-first delete (204)
 *
 * `?for=select` (Campaign attachments chunk B, AH-092) switches `index` to
 * an UNPAGINATED, thin-projection mode for the Attachments tab's attach
 * picker — the BrandController/BrandOptionResource precedent (AH-085): an
 * agency's file library is realistically dozens of rows, uploaded one at a
 * time through a form, never the hundreds a creator roster runs to, so a
 * whole-set fetch is the honest fix rather than the paginated default or a
 * `?q=` search shape. No "already attached to this campaign" filter here —
 * the picker dialog cross-references against the tab's own already-fetched
 * attachment list client-side, so this endpoint stays one shared, cacheable
 * shape regardless of which campaign is asking.
 *
 * Every `{file}` route explicitly checks `$file->agency_id === $agency->id`
 * BEFORE `Gate::authorize(...)` — route-model binding resolves `{file}`
 * before tenancy context exists (the BrandLogoController precedent), so a
 * cross-tenant file 404s (never 403 — non-fingerprinting,
 * docs/05-SECURITY-COMPLIANCE.md §7) rather than reaching the policy.
 */
final class AgencyFileController
{
    public function __construct(
        private readonly AgencyFileUploadService $uploads,
    ) {}

    public function index(Request $request, Agency $agency): JsonResponse
    {
        Gate::authorize('viewAny', AgencyFile::class);

        if ($request->query('for') === 'select') {
            $files = AgencyFile::query()
                ->where('agency_id', $agency->id)
                ->orderBy('original_filename')
                ->get();

            return response()->json([
                'data' => AgencyFileOptionResource::collection($files),
            ]);
        }

        $perPage = (int) $request->integer('per_page', 25);
        $perPage = max(1, min($perPage, 100));

        $paginator = AgencyFile::query()
            // Belt-and-suspenders on top of the BelongsToAgency global scope
            // (mirrors AgencyCreatorController) — agency A never sees agency
            // B's files even if the scope were ever bypassed upstream.
            ->where('agency_id', $agency->id)
            ->with('uploadedBy:id,ulid,name')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => AgencyFileResource::collection($paginator->items()),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function initUpload(Request $request, Agency $agency): JsonResponse
    {
        Gate::authorize('create', AgencyFile::class);

        $request->validate([
            'mime_type' => ['required', 'string'],
            'declared_bytes' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $payload = $this->uploads->initiatePresignedUpload(
                $agency,
                (string) $request->string('mime_type'),
                (int) $request->integer('declared_bytes'),
            );
        } catch (RuntimeException $e) {
            return ErrorResponse::single($request, 422, 'agency_file.presign_failed', $e->getMessage());
        }

        return response()->json(['data' => $payload]);
    }

    public function completeUpload(Request $request, Agency $agency): JsonResponse
    {
        Gate::authorize('create', AgencyFile::class);

        $request->validate([
            'upload_id' => ['required', 'string'],
            'original_filename' => ['required', 'string', 'max:255'],
        ]);

        try {
            $verified = $this->uploads->completePresignedUpload(
                $agency,
                (string) $request->string('upload_id'),
            );
        } catch (RuntimeException $e) {
            return ErrorResponse::single($request, 422, 'agency_file.complete_failed', $e->getMessage());
        }

        /** @var User $actor */
        $actor = $request->user();

        $file = AgencyFile::query()->create([
            'agency_id' => $agency->id,
            'original_filename' => (string) $request->string('original_filename'),
            'storage_key' => $verified['storage_key'],
            'mime_type' => $verified['mime_type'],
            'size_bytes' => $verified['size_bytes'],
            'category' => AgencyFileCategory::CampaignAttachment,
            'uploaded_by_user_id' => $actor->id,
        ]);

        Audit::log(
            action: AuditAction::AgencyFileUploaded,
            subject: $file,
            after: $file->toArray(),
        );

        return (new AgencyFileResource($file))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, Agency $agency, AgencyFile $file): JsonResponse
    {
        $this->assertBelongsToAgency($file, $agency);
        Gate::authorize('view', $file);

        return (new AgencyFileResource($file))->response();
    }

    public function destroy(Request $request, Agency $agency, AgencyFile $file): JsonResponse
    {
        $this->assertBelongsToAgency($file, $agency);
        Gate::authorize('delete', $file);

        if ($file->hasAttachments()) {
            return ErrorResponse::single(
                $request,
                Response::HTTP_CONFLICT,
                'agency_file.attached',
                'This file is attached to a campaign and cannot be deleted.',
            );
        }

        $before = $file->toArray();

        try {
            $this->uploads->delete($agency, $file);
        } catch (StorageDeleteFailedException|RuntimeException $e) {
            return ErrorResponse::single($request, 500, 'agency_file.delete_failed', $e->getMessage());
        }

        DB::transaction(function () use ($file): void {
            $file->delete();
        });

        Audit::log(
            action: AuditAction::AgencyFileDeleted,
            subject: $file,
            before: $before,
        );

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * 404, not 403 — the same non-fingerprinting posture as every other
     * agency-scoped route (docs/05-SECURITY-COMPLIANCE.md §7).
     */
    private function assertBelongsToAgency(AgencyFile $file, Agency $agency): void
    {
        if ($file->agency_id !== $agency->id) {
            abort(404);
        }
    }
}
