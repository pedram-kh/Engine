<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Impersonation\ImpersonationContext;
use App\Core\Storage\BracketSafeFilesystem;
use App\Core\Tenancy\TenancyContext;
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

        // Per-request tenant context. Read by App\Core\Tenancy\BelongsToAgencyScope
        // and the BelongsToAgency trait. Written by route-binding middleware
        // (added in Sprint 2 when the first /api/v1/agencies/{agency}/* routes
        // ship). Singleton means same instance for the lifetime of one
        // request / job / artisan invocation.
        $this->app->singleton(TenancyContext::class);

        // Per-request impersonation context (Sprint 13, D-9). Mirror of
        // TenancyContext: written by the impersonation enforcement
        // middleware, read by AuditLogger to stamp the dual-audit
        // impersonator_user_id column. Same single-instance-per-request
        // lifetime so the write and the reads see the same object.
        $this->app->singleton(ImpersonationContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
