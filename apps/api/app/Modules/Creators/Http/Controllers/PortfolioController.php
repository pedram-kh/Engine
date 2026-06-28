<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Creators\Enums\PortfolioItemKind;
use App\Modules\Creators\Enums\PortfolioProcessingStatus;
use App\Modules\Creators\Jobs\ProcessPortfolioImageJob;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorPortfolioItem;
use App\Modules\Creators\Services\CompletenessScoreCalculator;
use App\Modules\Creators\Services\PortfolioUploadService;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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
 * Each path enforces the per-creator item cap
 * ({@see PortfolioUploadService::MAX_ITEMS_PER_CREATOR}) and refreshes the
 * stored completeness score (the portfolio step flips on the first add /
 * last delete).
 */
final class PortfolioController
{
    public function __construct(
        private readonly PortfolioUploadService $service,
        private readonly CompletenessScoreCalculator $calculator,
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

        $this->refreshCompleteness($creator);

        return response()->json([
            'data' => [
                'id' => $item->ulid,
                'kind' => $item->kind->value,
                's3_path' => $item->s3_path,
                'position' => $item->position,
            ],
        ], 201);
    }

    /**
     * Start a presigned-PUT upload for a large portfolio image (AH-004 Q5/D8).
     * Mirrors the video init; the client PUTs the raw bytes to the returned
     * URL, then calls completeImageUpload().
     */
    public function initiateImageUpload(Request $request): JsonResponse
    {
        $request->validate([
            'mime_type' => ['required', 'string'],
            'declared_bytes' => ['required', 'integer', 'min:1'],
        ]);

        $creator = $this->resolveCreator($request);
        $this->assertHasCapacity($creator);

        try {
            $payload = $this->service->initiatePresignedImageUpload(
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

    /**
     * Finalise a presigned image upload (AH-004 Q5). Verifies the object
     * landed, creates the item as `processing` (its signed URL is WITHHELD by
     * every resource until ready), and dispatches the EXIF-strip / thumbnail
     * worker. The raw, EXIF-bearing object is never served in the meantime.
     */
    public function completeImageUpload(Request $request): JsonResponse
    {
        $request->validate([
            'upload_id' => ['required', 'string'],
            'title' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'mime_type' => ['required', 'string'],
            'size_bytes' => ['required', 'integer', 'min:1'],
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
                    'kind' => PortfolioItemKind::Image->value,
                    'processing_status' => PortfolioProcessingStatus::Processing->value,
                    'title' => $request->string('title')->value() ?: null,
                    'description' => $request->string('description')->value() ?: null,
                    's3_path' => $path,
                    'mime_type' => (string) $request->string('mime_type'),
                    'size_bytes' => (int) $request->integer('size_bytes'),
                    'position' => $this->nextPosition($creator),
                ]);
            });
        } catch (RuntimeException $e) {
            return ErrorResponse::single($request, 422, 'portfolio.complete_failed', $e->getMessage());
        }

        ProcessPortfolioImageJob::dispatch($item->id);

        // The item counts toward the portfolio step the moment it exists
        // (a `processing` item still satisfies "at least one portfolio
        // item"), so refresh now rather than waiting for the worker.
        $this->refreshCompleteness($creator);

        return response()->json([
            'data' => [
                'id' => $item->ulid,
                'kind' => $item->kind->value,
                'processing_status' => $item->processing_status->value,
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
            // Optional client-captured poster frame. The SPA grabs a frame
            // from the video at upload time (browsers can't render an .mp4
            // into an <img>), so this gives the gallery a real thumbnail.
            // Re-encoded server-side via uploadImage() (EXIF-stripped).
            'thumbnail' => ['nullable', 'file', 'image', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $creator = $this->resolveCreator($request);
        $this->assertHasCapacity($creator);

        $thumbnail = $request->file('thumbnail');
        $thumbnailFile = $thumbnail instanceof UploadedFile ? $thumbnail : null;

        try {
            $item = DB::transaction(function () use ($creator, $request, $thumbnailFile): CreatorPortfolioItem {
                $path = $this->service->completePresignedUpload(
                    $creator,
                    (string) $request->string('upload_id'),
                );

                $thumbnailPath = $thumbnailFile !== null
                    ? $this->service->uploadImage($creator, $thumbnailFile)
                    : null;

                return CreatorPortfolioItem::create([
                    'creator_id' => $creator->id,
                    'kind' => PortfolioItemKind::Video->value,
                    'title' => $request->string('title')->value() ?: null,
                    'description' => $request->string('description')->value() ?: null,
                    's3_path' => $path,
                    'thumbnail_path' => $thumbnailPath,
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

        $this->refreshCompleteness($creator);

        return response()->json([
            'data' => [
                'id' => $item->ulid,
                'kind' => $item->kind->value,
                's3_path' => $item->s3_path,
                'position' => $item->position,
            ],
        ], 201);
    }

    /**
     * Add a link portfolio item (AH-004 D9). Migration-free — the kind / enum /
     * external_url column / read-path already exist; this only adds the write +
     * validation + the add-link affordance behind it.
     *
     * URL validation is an XSS guard: the gallery renders `external_url` as a
     * clickable `href`, so only `http`/`https` are allowed and `javascript:` /
     * `data:` (and any other scheme) are rejected, with a length bound matching
     * the column.
     */
    public function createLink(Request $request): JsonResponse
    {
        $request->validate([
            'external_url' => [
                'required',
                'string',
                'max:2048',
                'url',
                // Scheme allowlist: http/https only. `javascript:` / `data:` /
                // `file:` etc. fail this anchored pattern and are rejected.
                'regex:/^https?:\/\//i',
            ],
            'title' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [
            'external_url.regex' => 'The link must be an http or https URL.',
        ]);

        $creator = $this->resolveCreator($request);
        $this->assertHasCapacity($creator);

        $item = CreatorPortfolioItem::create([
            'creator_id' => $creator->id,
            'kind' => PortfolioItemKind::Link->value,
            'processing_status' => PortfolioProcessingStatus::Ready->value,
            'title' => $request->string('title')->value() ?: null,
            'description' => $request->string('description')->value() ?: null,
            'external_url' => (string) $request->string('external_url'),
            'position' => $this->nextPosition($creator),
        ]);

        $this->refreshCompleteness($creator);

        return response()->json([
            'data' => [
                'id' => $item->ulid,
                'kind' => $item->kind->value,
                'processing_status' => $item->processing_status->value,
                'external_url' => $item->external_url,
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

        // AH-004: clean up the stored objects so a removed item — including a
        // `failed` one whose raw upload is unreachable behind the resource
        // gate — never lingers as orphaned S3 storage. Link items have no
        // s3_path/thumbnail_path and are skipped by the helper.
        $this->service->deleteStoredObjects($item->s3_path, $item->thumbnail_path);

        $item->delete();

        // Deleting the last item flips the portfolio step back to incomplete;
        // recompute so the stored score doesn't over-report.
        $this->refreshCompleteness($creator);

        return response()->json([], 204);
    }

    /**
     * Recompute + persist the 0–100 completeness score after a portfolio
     * mutation. The portfolio step flips complete/incomplete on the first
     * add / last delete, so — like the profile / social / tax write paths
     * in CreatorWizardService — these endpoints must refresh the stored
     * score; otherwise it goes stale (e.g. stuck at 80% after the creator
     * has actually reached 100%).
     */
    private function refreshCompleteness(Creator $creator): void
    {
        $creator->profile_completeness_score = $this->calculator->score($creator);
        $creator->save();
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
