<?php

declare(strict_types=1);

namespace App\Modules\Campaigns;

use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Listeners\CreateAssignmentAvailabilityBlock;
use App\Modules\Campaigns\Listeners\DispatchPostedContentVerification;
use App\Modules\Campaigns\Listeners\SendAssignmentNotifications;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Policies\CampaignPolicy;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CampaignsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Module contract bindings are added by the sprint that builds this module.
    }

    public function boot(): void
    {
        $this->registerPolicies();
        $this->registerEventListeners();
        $this->registerRoutes();
    }

    private function registerPolicies(): void
    {
        Gate::policy(Campaign::class, CampaignPolicy::class);
    }

    private function registerEventListeners(): void
    {
        // The accept auto-block (Sprint 8, D-11) — the first
        // AssignmentTransitioned consumer, mirroring IdentityServiceProvider's
        // listener wiring.
        Event::listen(AssignmentTransitioned::class, [CreateAssignmentAvailabilityBlock::class, 'handle']);

        // Sprint 9 Chunk 2: the 2nd consumer dispatches the social
        // verification job on `assignment.posted_by_creator` (D-10); the 3rd
        // sends the review notification set (D-14).
        Event::listen(AssignmentTransitioned::class, [DispatchPostedContentVerification::class, 'handle']);
        Event::listen(AssignmentTransitioned::class, [SendAssignmentNotifications::class, 'handle']);
    }

    private function registerRoutes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/Routes/api.php');
    }
}
