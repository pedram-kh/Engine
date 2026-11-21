<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Http\Controllers\Concerns\ResolvesStrategyRoute;
use App\Modules\Strategy\Http\Resources\ConceptPresenter;
use App\Modules\Strategy\Models\WaveConcept;
use App\Modules\Strategy\Models\WaveConceptImage;
use App\Modules\Strategy\Services\ConceptImageService;
use App\Modules\Strategy\Services\ConceptImageStorage;
use App\Modules\Strategy\Services\ConceptService;
use App\Modules\Strategy\Services\StrategyRuleViolation;
use App\Modules\Strategy\Services\StrategyText;
use App\Modules\Strategy\StrategyLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reference images of a concept (PL-2.3 plan sections 1.5, 1.7, 3.5). The upload is a reservation then a verified
 * completion; reads are streamed through here with the user's own session (no presigned GET exists).
 */
final class ConceptImageController
{
    use ResolvesStrategyRoute;

    public function __construct(private readonly ConceptImageService $images, private readonly ConceptService $concepts) {}

    public function init(Request $request, Agency $agency, Brand $brand, string $wave, string $concept): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageReferences', WaveConcept::class);

        /** @var array{mime_type: string, declared_bytes: int, original_filename: string} $data */
        $data = Validator::make(StrategyText::cleanInput($request->all(), ['original_filename']), [
            'mime_type' => ['required', 'string', Rule::in(array_keys(ConceptImageStorage::MIME_TYPES))],
            'declared_bytes' => ['required', 'integer', 'min:1'],
            'original_filename' => ['required', 'string', 'max:'.StrategyLimits::IMAGE_FILENAME],
        ])->validate();

        /** @var User $actor */
        $actor = $request->user();

        try {
            $out = $this->images->init($agency, $brand, $model, $concept, $actor, $data['mime_type'], (int) $data['declared_bytes'], $data['original_filename']);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => $out], Response::HTTP_CREATED);
    }

    public function complete(Request $request, Agency $agency, Brand $brand, string $wave, string $concept): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageReferences', WaveConcept::class);

        /** @var array{upload_id: string} $data */
        $data = Validator::make($request->all(), ['upload_id' => ['required', 'string', 'max:255']])->validate();

        /** @var User $actor */
        $actor = $request->user();

        try {
            $out = $this->images->complete($brand, $model, $concept, $actor, $data['upload_id']);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        $base = '/api/v1/agencies/'.$agency->ulid.'/brands/'.$brand->ulid.'/waves/'.$model->ulid.'/strategy/concepts/'.$concept;

        return response()->json(
            ['data' => ConceptPresenter::image($out['image'], $base), 'meta' => ['reset_to_awaiting' => $out['reset_to_awaiting']]],
            Response::HTTP_CREATED,
        );
    }

    public function destroy(Request $request, Agency $agency, Brand $brand, string $wave, string $concept, string $image): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageReferences', WaveConcept::class);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $reset = $this->images->delete($brand, $model, $concept, $image, $actor);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['meta' => ['reset_to_awaiting' => $reset]]);
    }

    public function file(Request $request, Agency $agency, Brand $brand, string $wave, string $concept, string $image): StreamedResponse|JsonResponse
    {
        return $this->stream($request, $agency, $brand, $wave, $concept, $image, thumbnail: false);
    }

    public function thumbnail(Request $request, Agency $agency, Brand $brand, string $wave, string $concept, string $image): StreamedResponse|JsonResponse
    {
        return $this->stream($request, $agency, $brand, $wave, $concept, $image, thumbnail: true);
    }

    private function stream(Request $request, Agency $agency, Brand $brand, string $wave, string $concept, string $image, bool $thumbnail): StreamedResponse|JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('view', WaveConcept::class);

        try {
            $c = $this->concepts->find($model, $concept);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }
        $row = WaveConceptImage::query()->where('concept_id', $c->id)->where('ulid', $image)->where('status', WaveConceptImage::READY)->first();
        $key = $thumbnail ? $row?->thumb_key : $row?->storage_key;
        if ($row === null || $key === null) {
            return ErrorResponse::single($request, Response::HTTP_NOT_FOUND, 'not_found', 'Not found.');
        }

        $disk = ConceptImageStorage::disk();
        $stream = $disk->exists($key) ? $disk->readStream($key) : null;
        if (! is_resource($stream)) {
            return ErrorResponse::single($request, Response::HTTP_NOT_FOUND, 'not_found', 'Not found.');
        }

        $mime = $thumbnail ? 'image/jpeg' : (string) $row->mime_type;
        $attachment = ! $thumbnail && $request->boolean('download');
        $name = $thumbnail ? 'thumbnail.jpg' : preg_replace('/[^A-Za-z0-9._ -]/', '_', $row->original_filename);

        return response()->stream(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
            'Content-Disposition' => ($attachment ? 'attachment' : 'inline').'; filename="'.$name.'"',
        ]);
    }
}
