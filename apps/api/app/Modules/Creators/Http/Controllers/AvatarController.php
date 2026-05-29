<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Creators\Http\Resources\CreatorResource;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\AvatarUploadService;
use App\Modules\Creators\Services\CompletenessScoreCalculator;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * POST   /api/v1/creators/me/avatar  - upload (multipart "file")
 * DELETE /api/v1/creators/me/avatar  - remove
 *
 * Direct-multipart avatar upload. The service applies size + MIME
 * validation and re-encodes the image to strip EXIF (defense-in-depth
 * against accidental geo-PII leakage).
 *
 * Both endpoints return the canonical {@see CreatorResource} envelope
 * (matching every other wizard endpoint in CreatorWizardController),
 * so the SPA's `onboardingApi.uploadAvatar` / `deleteAvatar` typed as
 * `Promise<CreatorResourceEnvelope>` can refresh the full creator
 * state — including the freshly-minted presigned `avatar_url` — in
 * the same round-trip.
 */
final class AvatarController
{
    public function __construct(
        private readonly AvatarUploadService $service,
        private readonly CompletenessScoreCalculator $calculator,
    ) {}

    public function store(Request $request): JsonResponse
    {
        // Distinguish "the server's PHP limits silently dropped the upload"
        // from an ordinary oversized-file rejection. When `upload_max_filesize`
        // or `post_max_size` is exceeded, PHP discards the payload BEFORE
        // validation runs, so a naive `required` rule would mislead the
        // client with a generic "file is required" message. Surface a clear,
        // specific 413 instead (and the /health upload check + the
        // `uploads:check-limits` command exist to catch the misconfiguration
        // at deploy time so this path is never hit in a correct environment).
        if ($this->uploadDroppedByServerLimit($request)) {
            return ErrorResponse::single(
                $request,
                413,
                'avatar.too_large',
                'The uploaded file exceeds the server upload limit.',
                'The image was rejected by the server before it could be processed. '
                    .'Choose a smaller image, or ask an administrator to raise the upload limit.',
            );
        }

        $maxKilobytes = (int) ceil($this->service->maxBytes() / 1024);

        $request->validate([
            'file' => ['required', 'file', 'max:'.$maxKilobytes, 'mimes:jpg,jpeg,png,webp'],
        ]);

        $creator = $this->resolveCreator($request);
        $file = $request->file('file');

        // $request->file() returns UploadedFile|UploadedFile[]|null. The
        // form-request validation above already rejects array inputs, but
        // we keep the array branch as defense-in-depth for when this
        // method is called outside a validated request lifecycle.
        if (is_array($file) || $file === null) {
            return ErrorResponse::single($request, 422, 'avatar.missing_file', 'Missing or invalid file upload.');
        }

        try {
            DB::transaction(function () use ($creator, $file): void {
                $path = $this->service->upload($creator, $file);
                $creator->forceFill(['avatar_path' => $path])->save();
            });
        } catch (RuntimeException $e) {
            return ErrorResponse::single($request, 422, 'avatar.upload_failed', $e->getMessage());
        }

        return (new CreatorResource($creator->refresh(), $this->calculator))
            ->response();
    }

    public function destroy(Request $request): JsonResponse
    {
        $creator = $this->resolveCreator($request);

        DB::transaction(function () use ($creator): void {
            $path = $creator->avatar_path;
            if ($path !== null) {
                $this->service->delete($path);
            }
            $creator->forceFill(['avatar_path' => null])->save();
        });

        return (new CreatorResource($creator->refresh(), $this->calculator))
            ->response();
    }

    /**
     * Detect an upload that PHP discarded because it exceeded the runtime
     * limits, so the controller can return a precise 413 rather than a
     * misleading "file is required" 422.
     *
     * Two distinct failure shapes:
     *   - `upload_max_filesize` exceeded: the file still arrives in $_FILES
     *     but with the UPLOAD_ERR_INI_SIZE error code (no usable tmp file).
     *   - `post_max_size` exceeded: PHP throws away the ENTIRE request body,
     *     so a POST that declared a non-zero Content-Length arrives with no
     *     parsed files AND no parsed form fields.
     */
    private function uploadDroppedByServerLimit(Request $request): bool
    {
        $file = $request->file('file');
        if ($file instanceof UploadedFile && $file->getError() === UPLOAD_ERR_INI_SIZE) {
            return true;
        }

        if (! $request->isMethod('POST')) {
            return false;
        }

        $declaredLength = (int) $request->server('CONTENT_LENGTH', 0);

        return $declaredLength > 0
            && $request->allFiles() === []
            && $request->post() === [];
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
