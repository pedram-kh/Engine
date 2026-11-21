<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Enums\MatrixLevel;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Strategy\Models\WaveMatrixCell;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WaveUsp;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/** PL-2.3 — fixtures for the concept specs: personas, USPs and cells of the strategy world, concepts and images. */
final class ConceptFixtures
{
    /** @param  array<string, mixed>  $w  a StrategyFixtures::world() result */
    public static function persona(array $w, string $market = 'SK'): WavePersona
    {
        return WavePersona::factory()->create(['wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id, 'market_id' => $w['mkid'][$market]]);
    }

    /** @param  array<string, mixed>  $w */
    public static function usp(array $w, string $title = 'Affordability'): WaveUsp
    {
        return WaveUsp::factory()->create(['wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id, 'title' => $title]);
    }

    public static function cell(WavePersona $persona, WaveUsp $usp, MatrixLevel $level): WaveMatrixCell
    {
        return WaveMatrixCell::factory()->create(['persona_id' => $persona->id, 'usp_id' => $usp->id, 'level' => $level]);
    }

    /**
     * @param  array<string, mixed>  $w
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    public static function create(array $w, WavePersona $persona, array $extra = [], ?User $as = null): TestResponse
    {
        return StrategyFixtures::call($as ?? $w['staff'], 'POST', StrategyFixtures::u($w, 'concepts'), array_merge(['market' => $persona->market->ulid, 'persona' => $persona->ulid, 'title' => 'One Order, No Drama'], $extra));
    }

    /** @param  array<string, mixed>  $w */
    public static function make(array $w, WavePersona $persona, array $extra = []): string
    {
        return (string) self::create($w, $persona, $extra)->assertCreated()->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $w
     * @return TestResponse<Response>
     */
    public static function decide(array $w, string $concept, string $status = 'approved', ?User $as = null): TestResponse
    {
        $body = $status === 'approved'
            ? ['status' => 'approved', 'client_name' => 'Anna K.']
            : ['status' => 'changes_requested', 'client_name' => 'Anna K.', 'comment' => 'Make the hook shorter.'];

        return StrategyFixtures::call($as ?? $w['manager'], 'POST', StrategyFixtures::u($w, "concepts/{$concept}/decision"), $body);
    }

    /**
     * The metadata of the audit rows of one action, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function auditMeta(string $action): array
    {
        return AuditLog::query()->where('action', $action)->orderBy('id')->get()->map(static fn (AuditLog $r): array => (array) $r->metadata)->all();
    }

    /**
     * The metadata of the latest audit row of an action ([] when there is none).
     *
     * @return array<string, mixed>
     */
    public static function lastAudit(string $action): array
    {
        $all = self::auditMeta($action);

        return $all === [] ? [] : $all[array_key_last($all)];
    }

    public static function png(int $w = 40, int $h = 30): string
    {
        $im = imagecreatetruecolor(max(1, $w), max(1, $h));
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 200, 60, 40));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    public static function jpeg(int $w = 40, int $h = 30): string
    {
        $im = imagecreatetruecolor(max(1, $w), max(1, $h));
        ob_start();
        imagejpeg($im);

        return (string) ob_get_clean();
    }

    public static function webp(int $w = 40, int $h = 30): string
    {
        $im = imagecreatetruecolor(max(1, $w), max(1, $h));
        ob_start();
        imagewebp($im);

        return (string) ob_get_clean();
    }

    /**
     * init, store the bytes at the reserved key as the browser would, complete.
     *
     * @param  array<string, mixed>  $w
     * @return TestResponse<Response> the complete response
     */
    public static function upload(array $w, string $concept, string $bytes, string $mime = 'image/png', string $name = 'ref.png', ?User $as = null, ?int $declared = null): TestResponse
    {
        $user = $as ?? $w['staff'];
        $init = StrategyFixtures::call($user, 'POST', StrategyFixtures::u($w, "concepts/{$concept}/images/uploads/init"), [
            'mime_type' => $mime, 'declared_bytes' => $declared ?? strlen($bytes), 'original_filename' => $name,
        ])->assertCreated();
        Storage::disk('media')->put((string) $init->json('data.upload_id'), $bytes);

        $done = StrategyFixtures::call($user, 'POST', StrategyFixtures::u($w, "concepts/{$concept}/images/uploads/complete"), ['upload_id' => $init->json('data.upload_id')]);

        // The thumbnail job ran on the sync queue; Pennant flushes its in-memory flag state after every job, and the test
        // flags live only there. Arm them again (a real queue runs the job in another process).
        WaveFixtures::armed();
        Feature::activate(StrategyEnabled::NAME);

        return $done;
    }
}
