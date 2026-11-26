<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Features\CreatorsScriptsEnabled;
use App\Modules\Strategy\Http\Controllers\Concerns\ResolvesStrategyRoute;
use App\Modules\Strategy\Http\Resources\ScriptPresenter;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptImage;
use App\Modules\Strategy\Services\ScriptImageService;
use App\Modules\Strategy\Services\ScriptImageStorage;
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
 * PL-3.2b: reference images of a hook or a line. The upload is a reservation then a verified completion; reads are streamed
 * through here with the user's own session (no presigned GET exists). ALL THREE flags first (404), then brand, wave, policy.
 */
final class ScriptImageController
{
    use ResolvesStrategyRoute;

    public function __construct(private readonly ScriptImageService $images) {}

    public function init(Request $request, Agency $agency, Brand $brand, string $wave, string $script): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageScripts', WaveScript::class);

        /** @var array{block: string, id: string, mime_type: string, declared_bytes: int, original_filename: string} $data */
        $data = Validator::make(StrategyText::cleanInput($request->all(), ['original_filename']), [
            'block' => ['required', 'string', Rule::in(['hook', 'line'])],
            'id' => ['required', 'string', 'size:26'],
            'mime_type' => ['required', 'string', Rule::in(array_keys(ScriptImageStorage::MIME_TYPES))],
            'declared_bytes' => ['required', 'integer', 'min:1'],
            'original_filename' => ['required', 'string', 'max:'.StrategyLimits::IMAGE_FILENAME],
        ])->validate();
        /** @var User $actor */
        $actor = $request->user();

        try {
            $out = $this->images->init($agency, $brand, $model, $actor, $script, $data['block'], $data['id'], $data['mime_type'], (int) $data['declared_bytes'], $data['original_filename']);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => $out], Response::HTTP_CREATED);
    }

    public function complete(Request $request, Agency $agency, Brand $brand, string $wave, string $script): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageScripts', WaveScript::class);

        /** @var array{upload_id: string} $data */
        $data = Validator::make($request->all(), ['upload_id' => ['required', 'string', 'max:512']])->validate();
        /** @var User $actor */
        $actor = $request->user();

        try {
            $image = $this->images->complete($brand, $model, $actor, $script, $data['upload_id']);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        $base = '/api/v1/agencies/'.$agency->ulid.'/brands/'.$brand->ulid.'/waves/'.$model->ulid.'/strategy/scripts/'.$script;

        return response()->json(['data' => ScriptPresenter::images([$image], $base)[0]], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, Agency $agency, Brand $brand, string $wave, string $script, string $image): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageScripts', WaveScript::class);
        /** @var User $actor */
        $actor = $request->user();

        try {
            $this->images->delete($brand, $model, $actor, $script, $image);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** The complete ordered list of the READY images of one block (a hook or a line). */
    public function order(Request $request, Agency $agency, Brand $brand, string $wave, string $script): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageScripts', WaveScript::class);

        /** @var array{block: string, id: string, ids: list<string>} $data */
        $data = Validator::make($request->all(), [
            'block' => ['required', 'string', Rule::in(['hook', 'line'])],
            'id' => ['required', 'string', 'size:26'],
            'ids' => ['required', 'array', 'max:'.StrategyLimits::IMAGES_PER_BLOCK],
            'ids.*' => ['required', 'string', 'size:26'],
        ])->validate();
        /** @var User $actor */
        $actor = $request->user();

        try {
            $row = $this->images->reorder($brand, $model, $actor, $script, $data['block'], $data['id'], array_values($data['ids']));
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => ScriptPresenter::script($row)]);
    }

    public function file(Request $request, Agency $agency, Brand $brand, string $wave, string $script, string $image): StreamedResponse|JsonResponse
    {
        return $this->stream($request, $agency, $brand, $wave, $script, $image, thumbnail: false);
    }

    public function thumbnail(Request $request, Agency $agency, Brand $brand, string $wave, string $script, string $image): StreamedResponse|JsonResponse
    {
        return $this->stream($request, $agency, $brand, $wave, $script, $image, thumbnail: true);
    }

    private function stream(Request $request, Agency $agency, Brand $brand, string $wave, string $script, string $image, bool $thumbnail): StreamedResponse|JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('view', WaveScript::class);

        $row = WaveScript::query()->where('wave_id', $model->id)->where('ulid', $script)->first();
        $img = $row === null ? null : WaveScriptImage::query()->where('script_id', $row->id)->where('ulid', $image)->where('status', WaveScriptImage::READY)->first();
        $key = $thumbnail ? $img?->thumb_key : $img?->storage_key;
        if ($img === null || $key === null) {
            return ErrorResponse::single($request, Response::HTTP_NOT_FOUND, 'not_found', 'Not found.');
        }

        $disk = ScriptImageStorage::disk();
        $stream = $disk->exists($key) ? $disk->readStream($key) : null;
        if (! is_resource($stream)) {
            return ErrorResponse::single($request, Response::HTTP_NOT_FOUND, 'not_found', 'Not found.');
        }

        $mime = $thumbnail ? 'image/jpeg' : (string) $img->mime_type;
        $attachment = ! $thumbnail && $request->boolean('download');
        $name = $thumbnail ? 'thumbnail.jpg' : preg_replace('/[^A-Za-z0-9._ -]/', '_', $img->original_filename);

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

    /** All three flags, read this flag FIRST (it short-circuits). */
    private function flagOff(Request $request): ?JsonResponse
    {
        if (CreatorsScriptsEnabled::active()) {
            return null;
        }

        return ErrorResponse::single($request, Response::HTTP_NOT_FOUND, 'not_found', 'Not found.');
    }
}
