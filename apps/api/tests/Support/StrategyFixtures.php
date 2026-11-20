<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Tenancy\TenancyContext;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Waves\Models\Wave;
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
    /**
     * @return array{agency: Agency, brand: Brand, admin: User, manager: User, staff: User, otherAgency: Agency, otherBrand: Brand, otherAdmin: User, wave: Wave, otherWave: Wave}
     */
    public static function world(): array
    {
        $w = WaveFixtures::world();
        WaveFixtures::armed();
        Feature::activate(StrategyEnabled::NAME);

        return $w + [
            'wave' => Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create(['markets' => ['SK', 'RO', 'CZ']]),
            'otherWave' => Wave::factory()->forBrand($w['otherAgency']->id, $w['otherBrand']->id)->create(['markets' => ['SK']]),
        ];
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
        ];
    }
}
