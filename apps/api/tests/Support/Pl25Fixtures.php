<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Waves\Models\WaveStep;
use App\Modules\Waves\Services\WaveStepProvisioner;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/** PL-2.5 — data through the API (so every hook runs), marks, hand-set requests and tracker row reads. */
final class Pl25Fixtures
{
    /**
     * @param  array<string, mixed>  $w
     * @return list<string> usp ulids
     */
    public static function usps(array $w, int $n = 1): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = (string) StrategyFixtures::call($w['admin'], 'POST', StrategyFixtures::u($w, 'usps'), ['title' => "USP {$i}", 'explainer' => 'x', 'creative_angle' => 'x', 'copy_seed' => 'x'])->assertCreated()->json('data.id');
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $w
     * @return list<string> persona ulids
     */
    public static function personas(array $w, string $market = 'SK', int $n = 1): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = (string) StrategyFixtures::call($w['admin'], 'POST', StrategyFixtures::u($w, 'personas'), ['market' => $w['mk'][$market], 'title' => "Persona {$market}{$i}", 'name' => "P{$i}", 'pronoun' => 'she'])->assertCreated()->json('data.id');
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $w
     * @return TestResponse<Response>
     */
    public static function cell(array $w, string $market, string $persona, string $usp, string $level = 'priority'): TestResponse
    {
        return StrategyFixtures::call($w['admin'], 'PUT', StrategyFixtures::u($w, 'matrix/cells'), ['market' => $w['mk'][$market], 'cells' => [
            ['persona' => $persona, 'usp' => $usp, 'level' => $level, 'rationale' => 'Why', 'message_line' => 'Line'],
        ]]);
    }

    /**
     * @param  array<string, mixed>  $w
     * @return string concept ulid
     */
    public static function concept(array $w, string $market, string $persona): string
    {
        return (string) StrategyFixtures::call($w['staff'], 'POST', StrategyFixtures::u($w, 'concepts'), ['market' => $w['mk'][$market], 'persona' => $persona, 'title' => 'One Order'])->assertCreated()->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $w
     * @return TestResponse<Response>
     */
    public static function mark(array $w, string $step, ?string $market = null, string $who = 'staff'): TestResponse
    {
        $q = $market === null ? '' : '?market='.$w['mk'][$market];

        return StrategyFixtures::call($w[$who], 'PUT', StrategyFixtures::u($w, "completions/{$step}").$q);
    }

    /**
     * @param  array<string, mixed>  $w
     * @return TestResponse<Response>
     */
    public static function reopen(array $w, string $step, ?string $market = null, string $who = 'staff'): TestResponse
    {
        $q = $market === null ? '' : '?market='.$w['mk'][$market];

        return StrategyFixtures::call($w[$who], 'DELETE', StrategyFixtures::u($w, "completions/{$step}").$q);
    }

    /** @param  array<string, mixed>  $w */
    public static function row(array $w, string $key): WaveStep
    {
        $find = static fn (): ?WaveStep => WaveStep::query()->where('wave_id', $w['wave']->id)->where('step_key', $key)->first();
        // Provision only when the rows are missing, so a recorded request never carries the provisioner's own lock.
        if (($row = $find()) === null) {
            app(WaveStepProvisioner::class)->ensure($w['wave']);
            $row = $find();
        }

        return $row ?? throw new RuntimeException("no tracker row {$key}");
    }

    /**
     * Set a tracker row by hand, the way the web does.
     *
     * @param  array<string, mixed>  $w
     * @return TestResponse<Response>
     */
    public static function hand(array $w, string $key, string $status, bool $confirm = false, string $who = 'admin'): TestResponse
    {
        $step = self::row($w, $key);
        $body = ['status' => $status] + ($confirm ? ['confirm_incomplete' => true] : []);

        return StrategyFixtures::call($w[$who], 'PATCH', WaveFixtures::wavesUrl($w['agency'], $w['brand'], $w['wave']->ulid.'/steps/'.$step->ulid), $body);
    }

    /** @param  array<string, mixed>  $w
     * @return array{string, string} status, source */
    public static function st(array $w, string $key): array
    {
        $r = self::row($w, $key);

        return [$r->status->value, $r->status_source->value];
    }

    /** @return list<array<string, mixed>> */
    public static function stepAudits(string $reason = ''): array
    {
        return AuditLog::query()->where('action', 'wave_step.updated')->orderBy('id')->get()
            ->map(static fn (AuditLog $r): array => (array) $r->metadata)
            ->filter(static fn (array $m): bool => $reason === '' || ($m['reason'] ?? null) === $reason)->values()->all();
    }
}
