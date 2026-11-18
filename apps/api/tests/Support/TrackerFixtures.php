<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Tenancy\TenancyContext;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveStep;
use App\Modules\Waves\Services\WaveStepProvisioner;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;

use Symfony\Component\HttpFoundation\Response;

/**
 * PL-1.3 — fixtures for the tracker specs: the PL-1.1 world, a provisioned wave, one extra member to own steps, and
 * a request helper that forgets the tenancy context between calls (one request per process in production).
 */
final class TrackerFixtures
{
    /**
     * @return array{agency: Agency, brand: Brand, admin: User, manager: User, staff: User, otherAgency: Agency, otherBrand: Brand, otherAdmin: User, wave: Wave, member: User}
     */
    public static function world(bool $provisioned = true): array
    {
        $w = WaveFixtures::world();
        WaveFixtures::armed();
        $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
        if ($provisioned) {
            app(WaveStepProvisioner::class)->ensure($wave);
        }

        return $w + ['wave' => $wave, 'member' => User::factory()->agencyStaff($w['agency'])->createOne()];
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

    /**
     * A list of rows of a JSON path of the response, typed for the analyser.
     *
     * @param  TestResponse<Response>  $res
     * @return list<array<string, mixed>>
     */
    public static function rows(TestResponse $res, string $path): array
    {
        $rows = $res->json($path);

        return is_array($rows) ? array_values($rows) : [];
    }

    public static function url(Agency $agency, Brand $brand, Wave $wave, string $suffix = ''): string
    {
        return WaveFixtures::wavesUrl($agency, $brand, $wave->ulid.($suffix !== '' ? '/'.$suffix : ''));
    }

    public static function step(Wave $wave, string $key): WaveStep
    {
        return WaveStep::query()->where('wave_id', $wave->id)->where('step_key', $key)->firstOrFail();
    }

    public static function removeMembership(User $user, Agency $agency): void
    {
        AgencyMembership::query()->where('user_id', $user->id)->where('agency_id', $agency->id)->firstOrFail()->delete();
    }
}
