<?php

declare(strict_types=1);

namespace App\Modules\Brands\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Http\Resources\BrandResource;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Services\BrandLogoUploadService;
use App\Modules\Creators\Http\Controllers\AvatarController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * POST   /api/v1/agencies/{agency}/brands/{brand}/logo — upload (multipart "file")
 * DELETE /api/v1/agencies/{agency}/brands/{brand}/logo — remove
 *
 * AH-053 (D7), the {@see AvatarController}
 * pattern applied to brands. Both endpoints return the full
 * {@see BrandResource} envelope, so the SPA gets the freshly-signed `logo_url`
 * in the same round-trip as the write.
 *
 * Authorization is the brand UPDATE posture (admin + manager): replacing a
 * brand's public face is an edit of the brand. The cross-tenant check runs
 * before the policy for the same reason as everywhere else in this module —
 * route-model binding resolves `{brand}` before tenancy context exists, so
 * ownership is asserted explicitly and answered with 404, never 403.
 *
 * Note the interaction with the D6 floor: the logo endpoints do NOT run the
 * floor gate. They cannot leave a brand less complete than they found it
 * (upload only ever fills the field), and DELETE is the one action that can
 * legitimately drop a brand below the floor — an agency that removes a logo
 * has consciously made the brand incomplete, and the next content edit will
 * demand a replacement. Blocking the removal instead would leave an agency
 * unable to take down a logo it no longer has the rights to.
 */
final class BrandLogoController
{
    public function __construct(
        private readonly BrandLogoUploadService $service,
    ) {}

    public function store(Request $request, Agency $agency, Brand $brand): JsonResponse
    {
        $this->assertBelongsToAgency($brand, $agency);
        Gate::authorize('update', $brand);

        // PHP discards oversized bodies BEFORE validation, so a naive
        // `required` rule would report "file is required" for what is really a
        // server-limit rejection. Answer with a precise 413 instead (the
        // /health upload assertion exists so a correctly configured
        // environment never reaches this branch).
        if ($this->uploadDroppedByServerLimit($request)) {
            return ErrorResponse::single(
                $request,
                413,
                'brand_logo.too_large',
                'The uploaded file exceeds the server upload limit.',
                'The image was rejected by the server before it could be processed. '
                    .'Choose a smaller image, or ask an administrator to raise the upload limit.',
            );
        }

        $maxKilobytes = (int) ceil($this->service->maxBytes() / 1024);

        $request->validate([
            'file' => ['required', 'file', 'max:'.$maxKilobytes, 'mimes:jpg,jpeg,png,webp'],
        ]);

        $file = $request->file('file');

        if (is_array($file) || $file === null) {
            return ErrorResponse::single($request, 422, 'brand_logo.missing_file', 'Missing or invalid file upload.');
        }

        $before = $brand->toArray();

        try {
            DB::transaction(function () use ($brand, $file): void {
                $path = $this->service->upload($brand, $file);
                $brand->forceFill(['logo_path' => $path])->save();
            });
        } catch (RuntimeException $e) {
            // The magic-byte rejection lands here: a `.png`-named script has
            // already passed the extension rule above and is caught by content
            // inspection inside the service.
            return ErrorResponse::single($request, 422, 'brand_logo.upload_failed', $e->getMessage());
        }

        Audit::log(
            action: AuditAction::BrandUpdated,
            subject: $brand,
            before: $before,
            after: $brand->fresh()?->toArray() ?? [],
        );

        return (new BrandResource($brand->refresh()))->response();
    }

    public function destroy(Request $request, Agency $agency, Brand $brand): JsonResponse
    {
        $this->assertBelongsToAgency($brand, $agency);
        Gate::authorize('update', $brand);

        $before = $brand->toArray();

        DB::transaction(function () use ($brand): void {
            $path = $brand->logo_path;
            if ($path !== null) {
                $this->service->delete($path);
            }
            $brand->forceFill(['logo_path' => null])->save();
        });

        Audit::log(
            action: AuditAction::BrandUpdated,
            subject: $brand,
            before: $before,
            after: $brand->fresh()?->toArray() ?? [],
        );

        return (new BrandResource($brand->refresh()))->response();
    }

    /**
     * An upload PHP threw away because of its runtime limits — see the
     * AvatarController twin for the two distinct failure shapes.
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

    /**
     * 404, not 403 — the same non-fingerprinting posture as every other brand
     * route (docs/05-SECURITY-COMPLIANCE.md §7). Agency A probing agency B's
     * brand ULID learns nothing about whether it exists.
     */
    private function assertBelongsToAgency(Brand $brand, Agency $agency): void
    {
        if ($brand->agency_id !== $agency->id) {
            abort(404);
        }
    }
}
