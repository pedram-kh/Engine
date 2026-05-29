<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Health\UploadLimitChecker;
use Illuminate\Console\Command;

/**
 * Deploy-time gate: fail the pipeline if the PHP runtime cannot accept the
 * uploads the application advertises (config/uploads.php).
 *
 * Run this as part of a release/readiness step. A non-zero exit means the
 * environment's `upload_max_filesize` / `post_max_size` are below the app
 * cap and large uploads would be silently dropped in that environment.
 *
 * NOTE: this only covers the PHP layer. A reverse proxy's body-size cap
 * (e.g. nginx `client_max_body_size`) is invisible to PHP and must be
 * verified with an end-to-end upload smoke test.
 */
final class CheckUploadLimits extends Command
{
    protected $signature = 'uploads:check-limits';

    protected $description = 'Assert the PHP runtime upload limits meet the application upload cap (deploy gate).';

    public function handle(UploadLimitChecker $checker): int
    {
        $required = $checker->requiredBytes();
        $ceiling = $checker->effectiveCeilingBytes();

        $this->line(sprintf('Application upload cap : %s', $this->humanBytes($required)));
        $this->line(sprintf('PHP effective ceiling  : %s', $this->humanBytes($ceiling)));
        $this->line(sprintf('  upload_max_filesize  : %s', ini_get('upload_max_filesize') ?: '(unset)'));
        $this->line(sprintf('  post_max_size        : %s', ini_get('post_max_size') ?: '(unset)'));

        if ($checker->isSatisfied()) {
            $this->info('OK — the PHP runtime can accept uploads up to the application cap.');

            return self::SUCCESS;
        }

        $this->error(
            'PHP upload limits are BELOW the application cap. Uploads larger than the runtime '
            .'ceiling will be silently rejected. Raise upload_max_filesize and post_max_size '
            .'(and any reverse-proxy body-size limit) to at least the application cap.',
        );

        return self::FAILURE;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $power = (int) min(count($units) - 1, floor(log($bytes, 1024)));
        $value = $bytes / (1024 ** $power);

        return sprintf('%s %s', rtrim(rtrim(number_format($value, 2), '0'), '.'), $units[$power]);
    }
}
