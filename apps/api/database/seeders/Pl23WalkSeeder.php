<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Enums\PersonaPronoun;
use App\Modules\Strategy\Models\WaveConcept;
use App\Modules\Strategy\Models\WaveConceptImage;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Strategy\Services\ConceptDecisionService;
use App\Modules\Strategy\Services\ConceptImageStorage;
use App\Modules\Strategy\Services\ConceptLinkService;
use App\Modules\Strategy\Services\ConceptService;
use App\Modules\Strategy\Services\ConceptThumbnailer;
use App\Modules\Strategy\Services\MatrixService;
use App\Modules\Waves\Database\Factories\WaveFactory;
use App\Modules\Waves\Models\Wave;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * PL-2.3 — local data for the concepts walk (plan section 10). Self-contained: it does not need the PL-2.1 or PL-2.2 walk.
 *
 * Agency A "PL-2.3 Walk Agency A": admin@, manager@, staff@ (all `@pl23-walk.local`); brand Bolt Food with
 *   - Concepts Wave 1 (SK, RO, CZ): six USPs and a filled matrix. SK: personas SK1 and SK2 and THREE concepts, one in each
 *       status (Approved with a link, Awaiting, Changes requested with the client's comment). RO: two personas and NO concept
 *       (the empty state with Add). CZ: no persona (the "add personas first" empty state).
 *   - Concepts Wave 2 (SK, RO):     SK has TWO concepts, both Approved (the green banner and the under-three line). RO empty.
 *   - Concepts Wave 3 (SK, RO):     SK has a concept with three real images (JPG, PNG, WebP) and four links, Awaiting.
 *
 * Agency B "PL-2.3 Walk Agency B": admin@pl23-walk-b.local, brand Glovo, one wave with a persona and one concept (isolation).
 *
 * Everything goes through the same services the screen calls, so the chips, positions, decisions and audit rows are the real
 * ones. Persona codes are the server's defaults (market plus slot: SK1, SK2, RO1 ...). Images are real files made with GD and
 * written to the `media` disk; if GD or the disk is not available the seeder says so and continues without images. It writes the
 * files to upload during the walk (a JPG, a PNG, a WebP, a text file renamed .jpg, and with PL23_BIG_FILES=1 eight 40 MB PNGs for
 * the 300 MB total) to `storage/app/pl23-walk-files`. It does NOT touch any flag: `waves_enabled` and `strategy_enabled` stay as
 * they are; turn them on and off by hand in the admin app.
 *
 *     php artisan db:seed --class='Database\Seeders\Pl23WalkSeeder'
 *
 * GUARDED to local + testing. Idempotent: a second run changes nothing. Removed by {@see Pl23WalkCleanupSeeder}.
 */
final class Pl23WalkSeeder extends Seeder
{
    public const string DOMAIN = '@pl23-walk.local';

    public const string DOMAIN_B = '@pl23-walk-b.local';

    public const string AGENCY_A_SLUG = 'pl23-walk-agency-a';

    public const string AGENCY_B_SLUG = 'pl23-walk-agency-b';

    public const string PASSWORD = 'password-12chars';

    public const string ADMIN_EMAIL = 'admin'.self::DOMAIN;

    public const string MANAGER_EMAIL = 'manager'.self::DOMAIN;

    public const string STAFF_EMAIL = 'staff'.self::DOMAIN;

    public const string B_ADMIN_EMAIL = 'admin'.self::DOMAIN_B;

    /** Where the files to upload during the walk are written (under `storage/app`). */
    public const string FILES_DIR = 'pl23-walk-files';

    private const array USPS = [
        'Category entry points', 'Affordability', 'Selection', 'Speed & reliability', 'Super app convenience', 'Local restaurant discovery',
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Pl23WalkSeeder is only allowed in local and testing environments.');
        }

        if (! Agency::query()->where('slug', self::AGENCY_A_SLUG)->exists()) {
            DB::transaction(function (): void {
                $this->agencyA();
                $this->agencyB();
            });
            $this->images();
        }

        $this->files();
        $this->printUlids();
    }

    private function agencyA(): void
    {
        $agency = AgencyFactory::new()->createOne(['name' => 'PL-2.3 Walk Agency A', 'slug' => self::AGENCY_A_SLUG, 'country_code' => 'SK']);
        $admin = User::factory()->agencyAdmin($agency)->create(['email' => self::ADMIN_EMAIL, 'name' => 'Ada Admin', 'password' => self::PASSWORD]);
        User::factory()->agencyManager($agency)->create(['email' => self::MANAGER_EMAIL, 'name' => 'Max Manager', 'password' => self::PASSWORD]);
        User::factory()->agencyStaff($agency)->create(['email' => self::STAFF_EMAIL, 'name' => 'Sam Staff', 'password' => self::PASSWORD]);

        $bolt = Brand::factory()->forAgency($agency->id)->create(['name' => 'Bolt Food', 'slug' => 'pl23-bolt-food', 'color' => 'green']);
        $make = fn (string $name, string $goLive, array $markets): Wave => Wave::factory()->forBrand($agency->id, $bolt->id)->withMarkets(WaveFactory::singleCountryMarkets($markets))->create([
            'name' => $name, 'go_live_on' => $goLive, 'created_by_user_id' => $admin->id,
        ]);

        // Wave 1: three concepts in three statuses (SK), an empty market with personas (RO), a market with no persona (CZ).
        $w1 = $make('Concepts Wave 1', '2027-01-09', ['SK', 'RO', 'CZ']);
        $usps = $this->usps($w1, 6);
        $sk1 = $this->persona($w1, 'SK', 'The Office Lunch Organiser', 'Zuzana', PersonaPronoun::She, 'shared services, Bratislava');
        $sk2 = $this->persona($w1, 'SK', 'The Value Seeker', 'Peter', PersonaPronoun::He, 'good food, fair price, Košice');
        $ro1 = $this->persona($w1, 'RO', 'The Busy Parent', 'Ioana', PersonaPronoun::She, 'two children, no time to cook');
        $this->persona($w1, 'RO', 'The Night-Shift Student', 'Radu', PersonaPronoun::He, 'studies by day, eats late');
        $this->matrix($bolt, $w1, $admin, 'SK', [
            [$sk1, $usps[1], 'support', 'A team lunch that respects the office budget.', 'Kind to the office budget.'],
            [$sk1, $usps[2], 'priority', 'Something for the whole team.', 'Always something worth it.'],
            [$sk1, $usps[3], 'priority', 'On time for the meeting, every time.', 'On time for the meeting.'],
            [$sk2, $usps[1], 'priority', 'Great food that does not make you pay over the odds.', 'Great food, still fair.'],
            [$sk2, $usps[2], 'support', 'The range to always find something worth it.', 'Always something worth it.'],
        ]);
        $this->matrix($bolt, $w1, $admin, 'RO', [
            [$ro1, $usps[0], 'priority', 'Dinner is sorted before the children ask.', 'Dinner, sorted.'],
            [$ro1, $usps[1], 'support', 'The price at checkout is the price at the door.', 'No surprises at the door.'],
        ]);

        $approved = $this->concept($bolt, $w1, $admin, 'SK', $sk1, 'Team lunch, sorted', [
            'funnel_stage' => 'mofu', 'funnel_note' => 'Consideration',
            'product' => 'Bolt Food delivery', 'angle' => 'The office lunch without the group-chat argument',
            'primary_message' => 'One order, everyone fed, on time for the meeting.',
            'hypothesis' => 'Office organisers respond to certainty more than to discounts.',
            'hook' => 'Twelve people, one order, zero arguments.', 'format_visual' => '15s vertical video, office desk, phone on the table',
            'key_points' => ['Group order in under a minute', 'Delivered before the meeting', 'Fair prices for the office budget'],
            'payoff' => 'Lunch, handled.', 'cta' => 'Order for the team',
        ]);
        $awaiting = $this->concept($bolt, $w1, $admin, 'SK', $sk2, 'Fair price, great food', [
            'funnel_stage' => 'tofu', 'funnel_note' => 'Awareness',
            'angle' => 'Value you can taste', 'primary_message' => 'Great food that does not make you pay over the odds.',
            'hook' => 'Good food should not cost a fortune.', 'key_points' => ['No hidden fees', 'Local favourites'], 'cta' => 'See what is near you',
        ]);
        $changes = $this->concept($bolt, $w1, $admin, 'SK', $sk1, 'The Friday team treat', [
            'funnel_stage' => 'bofu', 'funnel_note' => 'Conversion',
            'angle' => 'Friday lunch as a team ritual', 'primary_message' => 'Make Friday the day the team eats well.',
            'hook' => 'Friday just got better.', 'cta' => 'Treat the team',
        ]);

        // Links first: a link added after a decision would send an Approved concept back to Awaiting.
        $links = app(ConceptLinkService::class);
        $links->add($bolt, $w1, $approved->ulid, $admin, 'https://www.example.com/brand-guidelines', 'Brand guidelines');
        $links->add($bolt, $w1, $awaiting->ulid, $admin, 'https://www.example.com/competitor-ad', 'Competitor ad, for tone');
        $decisions = app(ConceptDecisionService::class);
        $decisions->record($bolt, $w1, $approved->ulid, $admin, ['status' => 'approved', 'client_name' => 'Jana Nováková', 'note' => 'Good to go. Please keep the office setting.']);
        $decisions->record($bolt, $w1, $changes->ulid, $admin, ['status' => 'changes_requested', 'client_name' => 'Tomáš Kováč', 'comment' => 'The hook is too casual for our brand voice. Please make it warmer and shorter, and show a mixed team, not only young people.']);

        // Wave 2: two concepts, both approved; RO empty.
        $w2 = $make('Concepts Wave 2', '2027-02-01', ['SK', 'RO']);
        $usps2 = $this->usps($w2, 3);
        $p2 = $this->persona($w2, 'SK', 'The Lunch-Break Office Worker', 'Lenka', PersonaPronoun::She, 'thirty minutes to eat');
        $this->persona($w2, 'RO', 'The Busy Parent', 'Ioana', PersonaPronoun::She, 'two children');
        $this->matrix($bolt, $w2, $admin, 'SK', [[$p2, $usps2[0], 'priority', 'Back at the desk on time.', 'Lunch in thirty minutes.']]);
        $decisions = app(ConceptDecisionService::class);
        foreach (['Thirty-minute lunch', 'Lunch at the desk'] as $title) {
            $c = $this->concept($bolt, $w2, $admin, 'SK', $p2, $title, ['funnel_stage' => 'tofu', 'funnel_note' => 'Awareness', 'hook' => 'Back before the meeting.']);
            $decisions->record($bolt, $w2, $c->ulid, $admin, ['status' => 'approved', 'client_name' => 'Jana Nováková']);
        }

        // Wave 3: one concept for images and links.
        $w3 = $make('Concepts Wave 3', '2027-02-20', ['SK', 'RO']);
        $usps3 = $this->usps($w3, 3);
        $p3 = $this->persona($w3, 'SK', 'The Value Seeker', 'Peter', PersonaPronoun::He, 'good food, fair price');
        $this->persona($w3, 'RO', 'The Busy Parent', 'Ioana', PersonaPronoun::She, 'two children');
        $this->matrix($bolt, $w3, $admin, 'SK', [[$p3, $usps3[1], 'priority', 'Fair, always.', 'Fair, always.']]);
        $links = app(ConceptLinkService::class);
        $refs = $this->concept($bolt, $w3, $admin, 'SK', $p3, 'References and links', [
            'funnel_stage' => 'tofu', 'funnel_note' => 'Awareness', 'hook' => 'Look at this.', 'angle' => 'A concept with real images and links',
        ]);
        foreach ([['https://www.example.com/moodboard', 'Moodboard'], ['https://www.example.org/reference-video', 'Reference video'], ['https://example.com/a-very-long-reference-address/with/a/long/path?and=query', 'A reference with a very long address and a long title that wraps'], ['https://www.example.net/deck', 'Pitch deck']] as [$url, $title]) {
            $links->add($bolt, $w3, $refs->ulid, $admin, $url, $title);
        }
    }

    private function agencyB(): void
    {
        $agency = AgencyFactory::new()->createOne(['name' => 'PL-2.3 Walk Agency B', 'slug' => self::AGENCY_B_SLUG, 'country_code' => 'SK']);
        $admin = User::factory()->agencyAdmin($agency)->create(['email' => self::B_ADMIN_EMAIL, 'name' => 'Bea Admin', 'password' => self::PASSWORD]);
        $glovo = Brand::factory()->forAgency($agency->id)->create(['name' => 'Glovo', 'slug' => 'pl23-glovo', 'color' => 'amber']);
        $wave = Wave::factory()->forBrand($agency->id, $glovo->id)->withMarkets(WaveFactory::singleCountryMarkets(['SK', 'RO']))->create([
            'name' => 'Glovo Wave 1', 'go_live_on' => '2026-12-01', 'created_by_user_id' => $admin->id,
        ]);
        $this->usps($wave, 2);
        $persona = $this->persona($wave, 'SK', 'The Late-Night Snacker', 'Marek', PersonaPronoun::He, 'orders after ten');
        $this->concept($glovo, $wave, $admin, 'SK', $persona, 'Glovo concept (Agency B)', ['hook' => 'Isolation check.']);
    }

    /**
     * @return list<WaveUsp>
     */
    private function usps(Wave $wave, int $count): array
    {
        $out = [];
        foreach (array_slice(self::USPS, 0, $count) as $title) {
            $out[] = WaveUsp::factory()->create(['wave_id' => $wave->id, 'agency_id' => $wave->agency_id, 'title' => $title]);
        }

        return $out;
    }

    private function persona(Wave $wave, string $market, string $title, string $name, PersonaPronoun $pronoun, string $summary): WavePersona
    {
        return WavePersona::factory()->create([
            'wave_id' => $wave->id, 'agency_id' => $wave->agency_id, 'market_id' => WaveFactory::marketId($wave, $market),
            'title' => $title, 'name' => $name, 'pronoun' => $pronoun, 'summary' => $summary,
        ]);
    }

    /**
     * @param  list<array{0: WavePersona, 1: WaveUsp, 2: string, 3: string, 4: string}>  $rows
     */
    private function matrix(Brand $brand, Wave $wave, User $actor, string $market, array $rows): void
    {
        $changes = array_map(static fn (array $r): array => [
            'persona' => $r[0]->ulid, 'usp' => $r[1]->ulid, 'level' => $r[2], 'rationale' => $r[3], 'message_line' => $r[4],
        ], $rows);
        app(MatrixService::class)->save($brand, $wave, $actor, WaveFactory::marketUlid($wave, $market), $changes);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function concept(Brand $brand, Wave $wave, User $actor, string $market, WavePersona $persona, string $title, array $fields): WaveConcept
    {
        return app(ConceptService::class)->create($brand, $wave, $actor, [...$fields, 'market' => WaveFactory::marketUlid($wave, $market), 'persona' => $persona->ulid, 'title' => $title]);
    }

    /** Three real images (JPG, PNG, WebP) on the "References and links" concept, with their thumbnails. */
    private function images(): void
    {
        try {
            if (! function_exists('imagecreatetruecolor')) {
                throw new RuntimeException('GD is not available');
            }
            $agency = Agency::query()->where('slug', self::AGENCY_A_SLUG)->firstOrFail();
            $concept = WaveConcept::withoutGlobalScopes()->where('agency_id', $agency->id)->where('title', 'References and links')->firstOrFail();
            $wave = Wave::query()->findOrFail($concept->wave_id);
            $brand = Brand::query()->findOrFail($wave->brand_id);
            $prefix = ConceptImageStorage::conceptPrefix($agency, $brand, $wave, $concept);
            $position = 0;
            foreach ([['jpg', 'image/jpeg', 'moodboard.jpg'], ['png', 'image/png', 'logo-lockup.png'], ['webp', 'image/webp', 'storyboard.webp']] as [$ext, $mime, $name]) {
                $bytes = self::imageBytes($ext, 640 + 40 * $position, 400 + 30 * $position, $position);
                if ($bytes === null) {
                    continue;
                }
                $ulid = (string) Str::ulid();
                $key = ConceptImageStorage::imageKey($prefix, $ulid, $ext);
                ConceptImageStorage::disk()->put($key, $bytes);
                $image = WaveConceptImage::query()->create([
                    'ulid' => $ulid, 'agency_id' => $agency->id, 'wave_id' => $wave->id, 'market_id' => $concept->market_id, 'concept_id' => $concept->id,
                    'storage_key' => $key, 'original_filename' => $name, 'mime_type' => $mime, 'declared_bytes' => strlen($bytes), 'size_bytes' => strlen($bytes),
                    'status' => WaveConceptImage::READY, 'position' => ++$position,
                ]);
                app(ConceptThumbnailer::class)->generate($image);
            }
        } catch (Throwable $e) {
            $this->say('warn', 'Images were not seeded ('.$e->getMessage().'). The concept "References and links" has links only.');
        }
    }

    /** The files to upload during the walk. Never fails the seeder. */
    private function files(): void
    {
        try {
            $dir = storage_path('app/'.self::FILES_DIR);
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            foreach (['jpg' => 'walk-photo.jpg', 'png' => 'walk-graphic.png', 'webp' => 'walk-picture.webp'] as $ext => $name) {
                $bytes = function_exists('imagecreatetruecolor') ? self::imageBytes($ext, 800, 500, 3) : null;
                if ($bytes !== null) {
                    file_put_contents($dir.'/'.$name, $bytes);
                }
            }
            file_put_contents($dir.'/not-an-image.jpg', "This is plain text renamed to .jpg. The server must refuse it.\n");
            if (getenv('PL23_BIG_FILES') === '1' && function_exists('imagecreatetruecolor')) {
                for ($i = 1; $i <= 8; $i++) {
                    $big = $dir.'/big-'.$i.'.png';
                    if (! is_file($big)) {
                        file_put_contents($big, self::noisePng(4200, 3300));
                    }
                }
            }
            $this->say('line', 'Files to upload: '.$dir);
        } catch (Throwable $e) {
            $this->say('warn', 'Walk files were not written ('.$e->getMessage().').');
        }
    }

    private static function imageBytes(string $ext, int $w, int $h, int $seed): ?string
    {
        $im = imagecreatetruecolor(max(1, $w), max(1, $h));
        $palette = [[76, 141, 255], [52, 199, 123], [245, 165, 36], [240, 80, 110]];
        [$r, $g, $b] = $palette[$seed % 4];
        imagefilledrectangle($im, 0, 0, $w, $h, (int) imagecolorallocate($im, $r, $g, $b));
        imagefilledellipse($im, (int) ($w / 2), (int) ($h / 2), (int) ($h * 0.8), (int) ($h * 0.8), (int) imagecolorallocate($im, 255, 255, 255));
        ob_start();
        $ok = match ($ext) {
            'jpg' => imagejpeg($im, null, 85),
            'png' => imagepng($im),
            'webp' => function_exists('imagewebp') ? imagewebp($im, null, 80) : false,
            default => false,
        };
        $bytes = (string) ob_get_clean();

        return $ok && $bytes !== '' ? $bytes : null;
    }

    /** About 40 MB of PNG that does not compress: random noise. */
    private static function noisePng(int $w, int $h): string
    {
        $im = imagecreatetruecolor(max(1, $w), max(1, $h));
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x += 1) {
                imagesetpixel($im, $x, $y, random_int(0, 0xFFFFFF));
            }
        }
        ob_start();
        imagepng($im, null, 1);

        return (string) ob_get_clean();
    }

    private function printUlids(): void
    {
        $agency = Agency::query()->where('slug', self::AGENCY_A_SLUG)->first();
        if ($agency === null) {
            return;
        }
        $this->say('line', "Agency A: {$agency->ulid}  (password for every account: ".self::PASSWORD.')');
        $bolt = Brand::query()->where('agency_id', $agency->id)->where('name', 'Bolt Food')->first();
        if ($bolt !== null) {
            $this->say('line', "Bolt Food: brand {$bolt->ulid}");
            foreach (Wave::query()->where('brand_id', $bolt->id)->orderBy('id')->get() as $wave) {
                $this->say('line', "  {$wave->name}: /brands/{$bolt->ulid}/waves/{$wave->ulid}/strategy/concepts");
            }
        }
        $b = Agency::query()->where('slug', self::AGENCY_B_SLUG)->first();
        $glovo = $b === null ? null : Brand::query()->where('agency_id', $b->id)->first();
        $gw = $glovo === null ? null : Wave::query()->where('brand_id', $glovo->id)->first();
        if ($glovo !== null && $gw !== null) {
            $this->say('line', "Glovo (Agency B): /brands/{$glovo->ulid}/waves/{$gw->ulid}/strategy/concepts");
        }
    }

    /** Writes to the console when run from `db:seed`; silent when a test calls the seeder directly (no command is bound). */
    private function say(string $style, string $message): void
    {
        $command = $this->command;
        if (! $command instanceof Command) {
            return;
        }
        if ($style === 'warn') {
            $command->warn($message);
        } else {
            $command->line($message);
        }
    }
}
