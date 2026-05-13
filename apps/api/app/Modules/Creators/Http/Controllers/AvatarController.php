<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\AvatarUploadService;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * POST   /api/v1/creators/me/avatar  - upload (multipart "file")
 * DELETE /api/v1/creators/me/avatar  - remove
 *
 * Direct-multipart avatar upload. The service applies size + MIME
 * validation and re-encodes the image to strip EXIF (defense-in-depth
 * against accidental geo-PII leakage).
 */
final class AvatarController
{
    public function __construct(
        private readonly AvatarUploadService $service,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
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
            $path = DB::transaction(function () use ($creator, $file): string {
                $path = $this->service->upload($creator, $file);
                $creator->forceFill(['avatar_path' => $path])->save();

                return $path;
            });
        } catch (RuntimeException $e) {
            return ErrorResponse::single($request, 422, 'avatar.upload_failed', $e->getMessage());
        }

        return response()->json([
            'data' => ['avatar_path' => $path],
        ]);
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

        return response()->json([
            'data' => ['avatar_path' => null],
        ]);
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
