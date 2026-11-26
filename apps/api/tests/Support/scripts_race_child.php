<?php

/*
 * PL-3.2a: one side of the scripts two-connection races (PostgreSQL only). Boots the app in its own process, waits on the
 * advisory lock the parent holds (the barrier), then runs ONE script write (`create` a script, or `hook` edit a hook with a
 * given revision; PL-3.2b adds `line_add`, `line` (save a line at a revision) and `image_init` (reserve an image)) and prints the outcome as one JSON line. Never part of a request path; only the scripts race tests spawn it.
 */

declare(strict_types=1);

use App\Core\Tenancy\TenancyContext;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Features\CreatorsScriptsEnabled;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Strategy\Services\ScriptHookService;
use App\Modules\Strategy\Services\ScriptImageService;
use App\Modules\Strategy\Services\ScriptLineService;
use App\Modules\Strategy\Services\ScriptService;
use App\Modules\Strategy\Services\StrategyRuleViolation;
use App\Modules\Waves\Features\WavesEnabled;
use App\Modules\Waves\Models\Wave;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

$base = dirname(__DIR__, 2);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = json_decode((string) base64_decode((string) ($argv[1] ?? ''), true), true, 512, JSON_THROW_ON_ERROR);

$database = (string) DB::connection()->getDatabaseName();
if (! str_ends_with($database, '_test')) {
    fwrite(STDOUT, (string) json_encode(['error' => 'refusing a database that is not a throwaway _test database']));
    exit(2);
}

Feature::activate(WavesEnabled::NAME);
Feature::activate(StrategyEnabled::NAME);
Feature::activate(CreatorsScriptsEnabled::NAME);
app(TenancyContext::class)->setAgencyId((int) $args['agency_id']);

$brand = Brand::withoutGlobalScopes()->whereKey($args['brand_id'])->firstOrFail();
$wave = Wave::withoutGlobalScopes()->whereKey($args['wave_id'])->firstOrFail();
$actor = User::query()->whereKey($args['user_id'])->firstOrFail();

if ((int) $args['barrier'] > 0) {
    DB::select('select pg_advisory_lock(?)', [(int) $args['barrier']]);
    DB::select('select pg_advisory_unlock(?)', [(int) $args['barrier']]);
}

$out = ['side' => $args['side']];
try {
    if ($args['mode'] === 'create') {
        app(ScriptService::class)->create($brand, $wave, $actor, (string) $args['slot']);
    } elseif ($args['mode'] === 'line_add') {
        app(ScriptLineService::class)->add($brand, $wave, $actor, (string) $args['script']);
    } elseif ($args['mode'] === 'line') {
        app(ScriptLineService::class)->update($brand, $wave, $actor, (string) $args['script'], (string) $args['line'], ['texts' => ['en' => (string) $args['text']]], (int) $args['revision']);
    } elseif ($args['mode'] === 'image_init') {
        $agency = Agency::query()->withoutGlobalScopes()->findOrFail($wave->agency_id);
        app(ScriptImageService::class)->init($agency, $brand, $wave, $actor, (string) $args['script'], (string) $args['block'], (string) $args['owner'], 'image/png', (int) $args['declared'], 'a.png');
    } else {
        app(ScriptHookService::class)->update($brand, $wave, $actor, (string) $args['script'], (string) $args['hook'], ['texts' => ['audio' => ['en' => (string) $args['text']]]], (int) $args['revision']);
    }
    $out['result'] = 'ok';
} catch (StrategyRuleViolation $e) {
    $out['result'] = $e->errorCode;
}
fwrite(STDOUT, (string) json_encode($out));
