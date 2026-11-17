<?php

declare(strict_types=1);

namespace App\Modules\Brands\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Core\Storage\StorageDeleteFailedException;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Enums\BrandFileType;
use App\Modules\Brands\Enums\BrandStatus;
use App\Modules\Brands\Http\Resources\BrandFileResource;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\BrandFile;
use App\Modules\Brands\Services\BrandFileUploadService;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Features\WavesEnabled;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;
use RuntimeException;

/**
 * Client files of a brand (PL-1.1), gated by the `waves_enabled` flag (404 `not_found`, before the policy and
 * before the body is read):
 *
 *   GET    brands/{brand}/files                   index (newest first)
 *   POST   brands/{brand}/files/uploads/init      presigned PUT init
 *   POST   brands/{brand}/files/uploads/complete  verify + row create (201)
 *   GET    brands/{brand}/files/{file}            one file with a fresh signed download URL
 *   DELETE brands/{brand}/files/{file}            object-first delete (204)
 *
 * `{brand}` resolves archived brands (list and download keep working; upload and delete answer 422
 * `brand_file.brand_archived`). `{file}` is looked up here by ulid, scoped to agency and brand, so another
 * brand's or agency's file, or a soft-deleted one, is a 404 (never 403).
 */
final class BrandFileController
{
    public function __construct(private readonly BrandFileUploadService $uploads) {}

    public function index(Request $request, Agency $agency, Brand $brand): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        Gate::authorize('viewAny', BrandFile::class);

        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        $paginator = BrandFile::query()
            ->where('agency_id', $agency->id)
            ->where('brand_id', $brand->id)
            ->with('uploadedBy:id,ulid,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => BrandFileResource::collection($paginator->items()),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function initUpload(Request $request, Agency $agency, Brand $brand): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        Gate::authorize('create', BrandFile::class);

        if ($archived = $this->archived($request, $brand)) {
            return $archived;
        }

        $data = Validator::make($request->all(), [
            'mime_type' => ['required', 'string'],
            'declared_bytes' => ['required', 'integer', 'min:1'],
            'file_type' => ['required', 'string', Rule::in(BrandFileType::values())],
        ])->validate();

        try {
            $payload = $this->uploads->initiatePresignedUpload(
                $agency,
                $brand,
                (string) $data['mime_type'],
                (int) $data['declared_bytes'],
            );
        } catch (RuntimeException $e) {
            return ErrorResponse::single($request, Response::HTTP_UNPROCESSABLE_ENTITY, 'brand_file.presign_failed', $e->getMessage());
        }

        return response()->json(['data' => $payload]);
    }

    public function completeUpload(Request $request, Agency $agency, Brand $brand): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        Gate::authorize('create', BrandFile::class);

        if ($archived = $this->archived($request, $brand)) {
            return $archived;
        }

        $data = Validator::make($request->all(), [
            'upload_id' => ['required', 'string'],
            'original_filename' => ['required', 'string', 'max:255'],
            'file_type' => ['required', 'string', Rule::in(BrandFileType::values())],
        ])->validate();

        try {
            $verified = $this->uploads->completePresignedUpload($agency, $brand, (string) $data['upload_id']);
        } catch (RuntimeException $e) {
            return ErrorResponse::single($request, Response::HTTP_UNPROCESSABLE_ENTITY, 'brand_file.complete_failed', $e->getMessage());
        }

        /** @var User $actor */
        $actor = $request->user();

        $file = BrandFile::query()->create([
            'agency_id' => $agency->id,
            'brand_id' => $brand->id,
            'original_filename' => (string) $data['original_filename'],
            'storage_key' => $verified['storage_key'],
            'mime_type' => $verified['mime_type'],
            'size_bytes' => $verified['size_bytes'],
            'file_type' => (string) $data['file_type'],
            'uploaded_by_user_id' => $actor->id,
        ]);

        Audit::log(
            action: AuditAction::BrandFileUploaded,
            subject: $file,
            // Literal keys on purpose: AuditPayloadKeysGuardTest reads them statically and Erasure decides each.
            // No storage_key, no internal id.
            after: [
                'original_filename' => $file->original_filename,
                'file_type' => $file->file_type->value,
                'mime_type' => $file->mime_type,
                'size_bytes' => $file->size_bytes,
            ],
        );

        return (new BrandFileResource($file->load('uploadedBy')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, Agency $agency, Brand $brand, string $file): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findFile($file, $agency, $brand);
        Gate::authorize('view', $model);

        return (new BrandFileResource($model))->response();
    }

    public function destroy(Request $request, Agency $agency, Brand $brand, string $file): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findFile($file, $agency, $brand);
        Gate::authorize('delete', $model);

        if ($archived = $this->archived($request, $brand)) {
            return $archived;
        }

        try {
            $this->uploads->delete($agency, $brand, $model);
        } catch (StorageDeleteFailedException|RuntimeException $e) {
            return ErrorResponse::single($request, Response::HTTP_INTERNAL_SERVER_ERROR, 'brand_file.delete_failed', $e->getMessage());
        }

        DB::transaction(function () use ($model): void {
            $model->delete();
        });

        Audit::log(
            action: AuditAction::BrandFileDeleted,
            subject: $model,
            before: [
                'original_filename' => $model->original_filename,
                'file_type' => $model->file_type->value,
                'mime_type' => $model->mime_type,
                'size_bytes' => $model->size_bytes,
            ],
        );

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    private function flagOff(Request $request): ?JsonResponse
    {
        if (Feature::active(WavesEnabled::NAME)) {
            return null;
        }

        return ErrorResponse::single($request, Response::HTTP_NOT_FOUND, 'not_found', 'Not found.');
    }

    private function archived(Request $request, Brand $brand): ?JsonResponse
    {
        if (! $brand->trashed() && $brand->status !== BrandStatus::Archived) {
            return null;
        }

        return ErrorResponse::single(
            $request,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'brand_file.brand_archived',
            'An archived brand is read-only; restore it before changing its files.',
        );
    }

    /** 404, not 403: non-fingerprinting (docs/05-SECURITY-COMPLIANCE.md §7). */
    private function assertBrand(Brand $brand, Agency $agency): void
    {
        if ($brand->agency_id !== $agency->id) {
            abort(Response::HTTP_NOT_FOUND);
        }
    }

    private function findFile(string $ulid, Agency $agency, Brand $brand): BrandFile
    {
        return BrandFile::query()
            ->where('ulid', $ulid)
            ->where('agency_id', $agency->id)
            ->where('brand_id', $brand->id)
            ->with('uploadedBy:id,ulid,name')
            ->firstOrFail();
    }
}
