<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Tenancy\TenancyContext;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

use Symfony\Component\HttpFoundation\Response;

/**
 * PL-2.1 — fixtures for the strategy specs: the PL-1.1 world (agency, brand, admin, manager, staff, a second agency),
 * BOTH flags armed, a wave with three markets, a wave of the other agency, and a request helper that forgets the
 * tenancy context between calls (one request per process in production).
 */
final class StrategyFixtures
{
    /** A well-formed market ulid that belongs to no wave (for the "not one of the wave's markets" cases). */
    public const NO_MARKET = '01HZZZZZZZZZZZZZZZZZZZZZZZ';

    /**
     * The `mk` map of the LAST world() built, for helper functions that have no `$w` in scope (a test builds one world).
     *
     * @var array<string, string>
     */
    public static array $mk = [];

    /** @var array<string, int> */
    public static array $mkid = [];

    /**
     * `mk` and `mkid` map a market's short code (SK, RO, CZ) to its ulid and its id, so a test can say `$w['mk']['SK']`
     * (PL-2.4: a market is a row, addressed by ulid on the wire).
     *
     * @return array{agency: Agency, brand: Brand, admin: User, manager: User, staff: User, otherAgency: Agency, otherBrand: Brand, otherAdmin: User, wave: Wave, otherWave: Wave, mk: array<string, string>, mkid: array<string, int>}
     */
    public static function world(): array
    {
        $w = WaveFixtures::world();
        WaveFixtures::armed();
        Feature::activate(StrategyEnabled::NAME);

        $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->withMarkets([
            ['name' => 'Slovakia', 'code' => 'SK', 'countries' => ['SK']],
            ['name' => 'Romania', 'code' => 'RO', 'countries' => ['RO']],
            ['name' => 'Czechia', 'code' => 'CZ', 'countries' => ['CZ']],
        ])->create();

        self::$mk = self::mk($wave);
        self::$mkid = self::mkid($wave);

        return $w + [
            'wave' => $wave,
            'otherWave' => Wave::factory()->forBrand($w['otherAgency']->id, $w['otherBrand']->id)->create(),
            'mk' => self::mk($wave),
            'mkid' => self::mkid($wave),
        ];
    }

    /**
     * Short code => market ulid.
     *
     * @return array<string, string>
     */
    public static function mk(Wave $wave): array
    {
        return WaveMarket::withoutGlobalScopes()->where('wave_id', $wave->id)->orderBy('position')->pluck('ulid', 'code')->all();
    }

    /**
     * Short code => market id.
     *
     * @return array<string, int>
     */
    public static function mkid(Wave $wave): array
    {
        return WaveMarket::withoutGlobalScopes()->where('wave_id', $wave->id)->orderBy('position')->pluck('id', 'code')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * Edit the wave's markets the way the web does: read the wave (markets and their version), keep the markets whose short
     * code is in `$keep`, send the rest back with the version.
     *
     * @param  array<string, mixed>  $w
     * @param  list<string>  $keep
     * @return TestResponse<Response>
     */
    public static function keepMarkets(array $w, array $keep): TestResponse
    {
        $url = WaveFixtures::wavesUrl($w['agency'], $w['brand'], $w['wave']->ulid);
        $attrs = self::call($w['admin'], 'GET', $url)->assertOk()->json('data.attributes');
        $markets = array_values(array_filter($attrs['markets'], static fn (array $m): bool => in_array($m['code'], $keep, true)));

        return self::call($w['admin'], 'PATCH', $url, ['markets' => $markets, 'markets_version' => $attrs['markets_version']]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    public static function call(User $user, string $method, string $url, array $body = []): TestResponse
    {
        app(TenancyContext::class)->forget();

        return actingAs($user, 'web')->json($method, $url, $body);
    }

    public static function url(Agency $agency, Brand $brand, Wave $wave, string $suffix = ''): string
    {
        return WaveFixtures::wavesUrl($agency, $brand, $wave->ulid.'/strategy'.($suffix !== '' ? '/'.$suffix : ''));
    }

    /** @param  array<string, mixed>  $w  a world() result (or a superset of it) */
    public static function u(array $w, string $suffix = ''): string
    {
        return self::url($w['agency'], $w['brand'], $w['wave'], $suffix);
    }

    /** @return list<array{string, string}> method, suffix (after .../strategy/) */
    public static function routeTable(): array
    {
        $u = '01HZZZZZZZZZZZZZZZZZZZZZZZ';

        return [
            ['GET', ''], ['PATCH', 'audience-title'],
            ['GET', 'usps'], ['POST', 'usps'], ['PUT', 'usps/order'], ['PATCH', "usps/{$u}"], ['DELETE', "usps/{$u}"],
            ['GET', 'personas'], ['POST', 'personas'], ['POST', 'personas/copy'], ['PATCH', "personas/{$u}"], ['DELETE', "personas/{$u}"],
            ['POST', "personas/{$u}/angles"], ['PATCH', "personas/{$u}/angles/{$u}"], ['DELETE', "personas/{$u}/angles/{$u}"],
            ['POST', "personas/{$u}/angles/{$u}/use-matrix-line"],
            ['GET', 'matrix'], ['PUT', 'matrix/cells'],
            ['GET', 'concepts'], ['POST', 'concepts'], ['PATCH', "concepts/{$u}"], ['DELETE', "concepts/{$u}"], ['POST', "concepts/{$u}/duplicate"],
            ['POST', "concepts/{$u}/decision"], ['GET', "concepts/{$u}/decisions"],
            ['POST', "concepts/{$u}/images/uploads/init"], ['POST', "concepts/{$u}/images/uploads/complete"],
            ['GET', "concepts/{$u}/images/{$u}/file"], ['GET', "concepts/{$u}/images/{$u}/thumbnail"], ['DELETE', "concepts/{$u}/images/{$u}"],
            ['POST', "concepts/{$u}/links"], ['PATCH', "concepts/{$u}/links/{$u}"], ['DELETE', "concepts/{$u}/links/{$u}"],
        ];
    }
}
