<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptBroll;
use App\Modules\Strategy\Models\WaveScriptHook;
use App\Modules\Strategy\Models\WaveScriptLine;
use App\Modules\Strategy\Services\ScriptImageService;
use App\Modules\Strategy\Services\ScriptImageStorage;
use App\Modules\Strategy\Services\ScriptLanguageService;
use App\Modules\Strategy\Services\ScriptLineService;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Database\Factories\WaveFactory;
use App\Modules\Waves\Models\Wave;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * PL-3.2b — local data for the walk of lines, B-roll, languages and reference images. It RUNS {@see Pl32WalkSeeder} first (same
 * two agencies, same logins, same flags left OFF) and then adds, through the services the screens call:
 *
 *   - Languages: Slovakia has Slovak and Hungarian; the Nordics have Swedish. English is always there.
 *   - Lucia's first script: four lines (English and Slovak side by side; one Slovak-only line; one CTA), B-roll on the first two,
 *       one reference image on the first line and one on the first hook.
 *   - Martin's first script AT ITS LIMITS: 30 lines, 12 B-roll rows, and one line with 10 reference images (the caps, one step from refused).
 *   - Tomáš's script: the hostile string in a line and in a B-roll description (plain text, never markup).
 *   - Astrid's script: Swedish only (the estimate falls back to the market language).
 *
 * The images are small generated PNGs, stored on the `media` disk through the real reservation and verify path; remove them with
 * {@see Pl32WalkCleanupSeeder}, which deletes the objects first. It does NOT touch any feature flag.
 *
 *     php artisan db:seed --class='Database\Seeders\Pl32bWalkSeeder'
 *
 * GUARDED to local + testing. Idempotent. THROWAWAY DATABASES ONLY (the walk database `pl32_walk`; never `catalyst`).
 */
final class Pl32bWalkSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Pl32bWalkSeeder is only allowed in local and testing environments.');
        }

        $this->call(Pl32WalkSeeder::class);

        $agency = Agency::query()->where('slug', Pl32WalkSeeder::AGENCY_A_SLUG)->firstOrFail();
        $wave = Wave::query()->where('agency_id', $agency->id)->where('name', 'Scripts Wave 1')->firstOrFail();
        if (WaveScriptLine::query()->withoutGlobalScopes()->where('wave_id', $wave->id)->exists()) {
            $this->say('PL-3.2b walk data is already there.');

            return;
        }
        $brand = Brand::query()->where('agency_id', $agency->id)->where('name', 'Bolt Food')->firstOrFail();
        $admin = User::query()->where('email', Pl32WalkSeeder::ADMIN_EMAIL)->firstOrFail();

        $languages = app(ScriptLanguageService::class);
        $languages->set($brand, $wave, $admin, WaveFactory::marketUlid($wave, 'SK'), ['sk', 'hu']);
        $languages->set($brand, $wave, $admin, WaveFactory::marketUlid($wave, 'NOR'), ['sv']);

        $this->lucia($agency, $brand, $wave, $admin);
        $this->martin($agency, $brand, $wave, $admin);
        $this->tomas($brand, $wave, $admin);
        $this->astrid($brand, $wave, $admin);

        $this->say('PL-3.2b walk data: lines '.WaveScriptLine::query()->withoutGlobalScopes()->where('wave_id', $wave->id)->count().', B-roll '.WaveScriptBroll::query()->withoutGlobalScopes()->where('wave_id', $wave->id)->count().'.');
    }

    private function lucia(Agency $agency, Brand $brand, Wave $wave, User $admin): void
    {
        $script = $this->scriptOf($wave, 'Lucia Horváthová');
        $lines = [];
        for ($i = 0; $i < 4; $i++) {
            $lines[] = $this->addLine($brand, $wave, $admin, $script);
        }
        $this->save($brand, $wave, $admin, $script, $lines[0], ['texts' => ['en' => 'Lunch for the whole floor is one order away.', 'sk' => 'Obed pre celé poschodie je na jednu objednávku.']]);
        $this->save($brand, $wave, $admin, $script, $lines[1], ['texts' => ['en' => 'Everyone eats well, you only order once.', 'sk' => 'Všetci sa najedia, objednáte raz.', 'hu' => 'Mindenki jóllakik, te csak egyszer rendelsz.']]);
        $this->save($brand, $wave, $admin, $script, $lines[2], ['texts' => ['sk' => 'Iba po slovensky: tento riadok nemá anglickú verziu.']]);
        $this->save($brand, $wave, $admin, $script, $lines[3], ['texts' => ['en' => 'Order the team lunch in the app.', 'sk' => 'Objednajte tímový obed v aplikácii.'], 'cta' => true]);

        $service = app(ScriptLineService::class);
        foreach ([0, 1] as $i) {
            $service->brollAdd($brand, $wave, $admin, $script->ulid, $lines[$i]->ulid);
        }
        $service->brollAdd($brand, $wave, $admin, $script->ulid, $lines[0]->ulid);
        $rows = WaveScriptBroll::query()->withoutGlobalScopes()->where('script_id', $script->id)->orderBy('line_id')->orderBy('position')->get();
        [$first, $second, $third] = [$rows->get(0), $rows->get(1), $rows->get(2)];
        if ($first === null || $second === null || $third === null) {
            throw new RuntimeException('The three B-roll rows were not created.');
        }
        $this->save($brand, $wave, $admin, $script, $lines[0], ['broll' => [
            $first->ulid => ['shot_type' => 'close_up', 'description' => 'The delivery bag on the office kitchen counter.'],
            $second->ulid => ['shot_type' => 'detail', 'description' => 'Hands unpacking three boxes.'],
        ]]);
        $this->save($brand, $wave, $admin, $script, $lines[1], ['broll' => [$third->ulid => ['shot_type' => 'wide', 'description' => 'The whole team around the table.']]]);

        $this->image($agency, $brand, $wave, $admin, $script, 'line', $lines[0]->ulid, 'kitchen.png', 120, 80);
        $hook = WaveScriptHook::query()->withoutGlobalScopes()->where('script_id', $script->id)->orderBy('position')->firstOrFail();
        $this->image($agency, $brand, $wave, $admin, $script, 'hook', $hook->ulid, 'storyboard.png', 80, 120);
    }

    /** One script at all its limits: 30 lines, 12 B-roll rows, a line with 10 images. */
    private function martin(Agency $agency, Brand $brand, Wave $wave, User $admin): void
    {
        $script = $this->scriptOf($wave, 'Martin Kováč');
        $service = app(ScriptLineService::class);
        $lines = [];
        for ($i = 0; $i < StrategyLimits::LINES_PER_SCRIPT; $i++) {
            $line = $this->addLine($brand, $wave, $admin, $script);
            $this->save($brand, $wave, $admin, $script, $line, ['texts' => ['en' => 'Line '.($i + 2).': good food, fair price.']]);
            $lines[] = $line;
        }
        for ($i = 0; $i < StrategyLimits::BROLL_PER_SCRIPT; $i++) {
            $service->brollAdd($brand, $wave, $admin, $script->ulid, $lines[$i % 6]->ulid);
        }
        for ($i = 0; $i < StrategyLimits::IMAGES_PER_BLOCK; $i++) {
            $this->image($agency, $brand, $wave, $admin, $script, 'line', $lines[0]->ulid, "reference-{$i}.png", 60 + $i * 4, 40 + $i * 3);
        }
    }

    private function tomas(Brand $brand, Wave $wave, User $admin): void
    {
        $script = $this->scriptOf($wave, 'Tomáš Baláž');
        $line = $this->addLine($brand, $wave, $admin, $script);
        app(ScriptLineService::class)->brollAdd($brand, $wave, $admin, $script->ulid, $line->ulid);
        $row = WaveScriptBroll::query()->withoutGlobalScopes()->where('line_id', $line->id)->firstOrFail();
        $this->save($brand, $wave, $admin, $script, $line, [
            'texts' => ['en' => Pl32WalkSeeder::HOSTILE, 'sk' => Pl32WalkSeeder::HOSTILE],
            'broll' => [$row->ulid => ['shot_type' => 'detail', 'description' => Pl32WalkSeeder::HOSTILE]],
        ]);
    }

    private function astrid(Brand $brand, Wave $wave, User $admin): void
    {
        $script = $this->scriptOf($wave, 'Astrid Lindqvist');
        $line = $this->addLine($brand, $wave, $admin, $script);
        $this->save($brand, $wave, $admin, $script, $line, ['texts' => ['sv' => 'Det är redan mörkt klockan fyra. Middagen kommer till dig.']]);
    }

    private function scriptOf(Wave $wave, string $creatorName): WaveScript
    {
        return WaveScript::query()->withoutGlobalScopes()->where('wave_id', $wave->id)->whereNull('archived_at')
            ->whereIn('creator_id', Creator::query()->where('display_name', $creatorName)->select('id'))
            ->orderBy('number')->firstOrFail();
    }

    private function addLine(Brand $brand, Wave $wave, User $admin, WaveScript $script): WaveScriptLine
    {
        app(ScriptLineService::class)->add($brand, $wave, $admin, $script->ulid);

        return WaveScriptLine::query()->withoutGlobalScopes()->where('script_id', $script->id)->orderByDesc('position')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function save(Brand $brand, Wave $wave, User $admin, WaveScript $script, WaveScriptLine $line, array $changes): void
    {
        $fresh = WaveScriptLine::query()->withoutGlobalScopes()->whereKey($line->id)->firstOrFail();
        app(ScriptLineService::class)->update($brand, $wave, $admin, $script->ulid, $line->ulid, $changes, $fresh->revision);
    }

    private function image(Agency $agency, Brand $brand, Wave $wave, User $admin, WaveScript $script, string $block, string $owner, string $name, int $width, int $height): void
    {
        $im = imagecreatetruecolor(max(1, $width), max(1, $height));
        imagefill($im, 0, 0, (int) imagecolorallocate($im, random_int(40, 220), random_int(40, 220), random_int(40, 220)));
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();

        $service = app(ScriptImageService::class);
        $init = $service->init($agency, $brand, $wave, $admin, $script->ulid, $block, $owner, 'image/png', strlen($bytes), $name);
        ScriptImageStorage::disk()->put($init['upload_id'], $bytes);
        $service->complete($brand, $wave, $admin, $script->ulid, $init['upload_id']);
    }

    /** Writes to the console when run from `db:seed`; silent when a test calls the seeder directly. */
    private function say(string $message): void
    {
        if ($this->command instanceof Command) {
            $this->command->line($message);
        }
    }
}
