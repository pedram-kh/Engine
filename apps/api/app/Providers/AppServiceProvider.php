<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Storage\BracketSafeFilesystem;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Replace the default Filesystem singleton with one that handles
        // project paths containing literal brackets (e.g. "[PROJECT]").
        // PHP's glob() interprets brackets as character classes, breaking
        // Laravel's migrator, config loader, and translation loader.
        $this->app->singleton('files', fn () => new BracketSafeFilesystem);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
