<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Creators\Enums\PortfolioItemKind;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorPortfolioItem;
use App\Modules\Creators\Services\PortfolioUploadService;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Portfolio uploads and listing.
 *
 *   POST   /api/v1/creators/me/portfolio/images        direct image upload
 *   POST   /api/v1/creators/me/portfolio/videos/init   start presigned video upload
 *   POST   /api/v1/creators/me/portfolio/videos/complete  finish presigned video upload
 *   DELETE /api/v1/creators/me/portfolio/{item}        remove an item
 *
 * Each path enforces the per-creator 10-item cap (Spec §6.1 Step 4).
 */
final class PortfolioController
{
    public function __construct(
        private readonly PortfolioUploadService $service,
    ) {}

    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp'],
            'title' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $creator = $this->resolveCreator($request);
        $this->assertHasCapacity($creator);

        $file = $request->file('file');
        // $request->file() returns UploadedFile|UploadedFile[]|null. The
        // form-request validation above already rejects array inputs, but
        // we keep the array branch as defense-in-depth.
        if (is_array($file) || $file === null) {
            return ErrorResponse::single($request, 422, 'portfolio.missing_file', 'Missing or invalid file upload.');
        }

        try {
            $item = DB::transaction(function () use ($creator, $request, $file): CreatorPortfolioItem {
                $path = $this->service->uploadImage($creator, $file);

                return CreatorPortfolioItem::create([
                    'creator_id' => $creator->id,
                    'kind' => PortfolioItemKind::Image->value,
                    'title' => $request->string('title')->value() ?: null,
                    'description' => $request->string('description')->value() ?: null,
                    's3_path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'position' => $this->nextPosition($creator),
                ]);
            });
        } catch (RuntimeException $e) {
            return ErrorResponse::single($request, 422, 'portfolio.upload_failed', $e->getMessage());
        }

        return response()->json([
            'data' => [
                'id' => $item->ulid,
                'kind' => $item->kind->value,
                's3_path' => $item->s3_path,
                'position' => $item->position,
            ],
        ], 201);
    }

    public function initiateVideoUpload(Request $request): JsonResponse
    {
        $request->validate([
            'mime_type' => ['required', 'string'],
            'declared_bytes' => ['required', 'integer', 'min:1'],
        ]);

        $creator = $this->resolveCreator($request);
        $this->assertHasCapacity($creator);

        try {
            $payload = $this->service->initiatePresignedUpload(
                $creator,
                (string) $request->string('mime_type'),
                (int) $request->integer('declared_bytes'),
            );
        } catch (RuntimeException $e) {
            return ErrorResponse::single($request, 422, 'portfolio.presign_failed', $e->getMessage());
        }

        return response()->json([
            'data' => $payload,
        ]);
    }

    public function completeVideoUpload(Request $request): JsonResponse
    {
        $request->validate([
            'upload_id' => ['required', 'string'],
            'title' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'mime_type' => ['required', 'string'],
            'size_bytes' => ['required', 'integer', 'min:1'],
            'duration_seconds' => ['nullable', 'integer', 'min:1'],
        ]);

        $creator = $this->resolveCreator($request);
        $this->assertHasCapacity($creator);

        try {
            $item = DB::transaction(function () use ($creator, $request): CreatorPortfolioItem {
                $path = $this->service->completePresignedUpload(
                    $creator,
                    (string) $request->string('upload_id'),
                );

                return CreatorPortfolioItem::create([
                    'creator_id' => $creator->id,
                    'kind' => PortfolioItemKind::Video->value,
                    'title' => $request->string('title')->value() ?: null,
                    'description' => $request->string('description')->value() ?: null,
                    's3_path' => $path,
                    'mime_type' => (string) $request->string('mime_type'),
                    'size_bytes' => (int) $request->integer('size_bytes'),
                    'duration_seconds' => $request->filled('duration_seconds')
                        ? (int) $request->integer('duration_seconds')
                        : null,
                    'position' => $this->nextPosition($creator),
                ]);
            });
        } catch (RuntimeException $e) {
            return ErrorResponse::single($request, 422, 'portfolio.complete_failed', $e->getMessage());
        }

        return response()->json([
            'data' => [
                'id' => $item->ulid,
                'kind' => $item->kind->value,
                's3_path' => $item->s3_path,
                'position' => $item->position,
            ],
        ], 201);
    }

    public function destroy(Request $request, string $portfolioItem): JsonResponse
    {
        $creator = $this->resolveCreator($request);

        $item = $creator->portfolioItems()->where('ulid', $portfolioItem)->first();

        if ($item === null) {
            return ErrorResponse::single($request, 404, 'portfolio.not_found', 'Portfolio item not found.');
        }

        $item->delete();

        return response()->json([], 204);
    }

    private function nextPosition(Creator $creator): int
    {
        $max = $creator->portfolioItems()->max('position');

        return (int) ($max ?? 0) + 1;
    }

    private function assertHasCapacity(Creator $creator): void
    {
        if ($creator->portfolioItems()->count() >= PortfolioUploadService::MAX_ITEMS_PER_CREATOR) {
            abort(response()->json([
                'errors' => [[
                    'status' => '409',
                    'code' => 'portfolio.cap_reached',
                    'detail' => 'A creator may store up to '.PortfolioUploadService::MAX_ITEMS_PER_CREATOR.' portfolio items.',
                ]],
            ], 409));
        }
    }

    private function resolveCreator(Request $request): Creator
    {
        /** @var User $user */
        $user = $request->user();
        $creator = $user->creator;

        if ($creator === null) {
            abort(response()->json([
                'errors' => [[
                    'status' => '404',
                    'code' => 'creator.not_found',
                    'detail' => 'No creator profile is associated with this user.',
                ]],
            ], 404));
        }

        return $creator;
    }
}
