<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Jobs;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Strategy\Models\WaveScriptImage;
use App\Modules\Strategy\Services\ScriptThumbnailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Makes the thumbnail of a script image after its upload completed (PL-3.2b). The DEFAULT queue (the `laravel-queue` worker, run
 * with `--memory=768`), the same as the concept job: no new queue name exists. One try: a decode that killed the worker once
 * would again. The thresholds that keep a decompression bomb away from the decoder are in the thumbnailer.
 */
final class GenerateScriptImageThumbnail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const string MEMORY_LIMIT_FLOOR = '768M';

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $imageId) {}

    public function handle(ScriptThumbnailer $thumbnailer): void
    {
        $limit = ini_get('memory_limit');
        if ($limit !== false && $limit !== '-1' && self::bytes($limit) < self::bytes(self::MEMORY_LIMIT_FLOOR)) {
            ini_set('memory_limit', self::MEMORY_LIMIT_FLOOR);
        }

        // No tenant context in a worker: the row is found by id.
        $image = WaveScriptImage::query()->withoutGlobalScope(BelongsToAgencyScope::class)->find($this->imageId);
        if ($image === null) {
            return;
        }

        $thumbnailer->generate($image);
    }

    private static function bytes(string $value): int
    {
        $n = (int) $value;

        return match (strtoupper(substr(trim($value), -1))) {
            'G' => $n * 1024 * 1024 * 1024,
            'M' => $n * 1024 * 1024,
            'K' => $n * 1024,
            default => $n,
        };
    }
}
