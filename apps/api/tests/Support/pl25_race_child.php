<?php

/*
 * PL-2.5 A2: one side of the two-connection race (PostgreSQL only). Boots the app in its own process, waits on the
 * advisory lock the parent holds (the barrier), then performs one action and prints the outcome as one JSON line.
 * Never part of a request path; only the race test spawns it.
 */

declare(strict_types=1);

use App\Core\Tenancy\TenancyContext;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Strategy\Services\ConceptDecisionService;
use App\Modules\Waves\Features\WavesEnabled;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Services\WaveRuleViolation;
use App\Modules\Waves\Services\WaveStepService;
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
app(TenancyContext::class)->setAgencyId((int) $args['agency_id']);

$brand = Brand::withoutGlobalScopes()->whereKey($args['brand_id'])->firstOrFail();
$wave = Wave::withoutGlobalScopes()->whereKey($args['wave_id'])->firstOrFail();
$actor = User::query()->whereKey($args['user_id'])->firstOrFail();

// The barrier: block until the parent releases it, so both children start their transaction together.
if ((int) $args['barrier'] > 0) {
    DB::select('select pg_advisory_lock(?)', [(int) $args['barrier']]);
    DB::select('select pg_advisory_unlock(?)', [(int) $args['barrier']]);
}

$out = ['action' => $args['action']];
try {
    if ($args['action'] === 'decide') {
        app(ConceptDecisionService::class)->record($brand, $wave, (string) $args['concept'], $actor, ['status' => 'approved', 'client_name' => 'Anna K.']);
        $out['result'] = 'ok';
    } else {
        app(WaveStepService::class)->update($brand, $wave, (string) $args['step'], $actor, ['status' => 'done']);
        $out['result'] = 'ok';
    }
} catch (WaveRuleViolation $e) {
    $out['result'] = $e->errorCode;
}
fwrite(STDOUT, (string) json_encode($out));
