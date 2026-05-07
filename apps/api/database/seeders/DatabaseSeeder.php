<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Top-level seeder. Composes per-sprint seeders so that running
 * `php artisan db:seed` reliably gives a developer a usable local
 * environment.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            Sprint1IdentitySeeder::class,
        ]);
    }
}
