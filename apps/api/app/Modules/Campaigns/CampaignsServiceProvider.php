<?php

declare(strict_types=1);

namespace App\Modules\Campaigns;

use App\Modules\Boards\Listeners\BoardAutomationListener;
use App\Modules\Boards\Listeners\CreateBoardCard;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Listeners\CreateAssignmentAvailabilityBlock;
use App\Modules\Campaigns\Listeners\CreateMessageThread;
use App\Modules\Campaigns\Listeners\DispatchPostedContentVerification;
use App\Modules\Campaigns\Listeners\SendAssignmentNotifications;
use App\Modules\Campaigns\Listeners\WriteSystemMessage;
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

        // Sprint 11 (D-3): the 4th consumer provisions the assignment's message
        // thread on invite. Idempotent (firstOrCreate on the assignment_id
        // UNIQUE); the lazy GET create heals any pre-existing thread-less
        // assignment, so no backfill migration is needed.
        Event::listen(AssignmentTransitioned::class, [CreateMessageThread::class, 'handle']);

        // Sprint 11 (D-4): the 5th consumer writes a system message into the
        // thread on curated lifecycle transitions (the WriteSystemMessage
        // allowlist). The thread is provisioned defensively first; system
        // messages write even on terminal events (D-13).
        Event::listen(AssignmentTransitioned::class, [WriteSystemMessage::class, 'handle']);

        // Sprint 12 Chunk 1 (D-5/D-6): the board consumers. ORDER IS LOCKED
        // (D-7) — CreateBoardCard (6th) provisions the card on invite BEFORE
        // BoardAutomationListener (7th) runs the `invited → Invited` automation
        // off the same event. The automation is ALSO a no-op on a missing card
        // (belt + suspenders), so the move can never be dropped by a slip here.
        // BoardAutomationListener binds to the AssignmentEventContract and
        // switches on eventKey() — no dedicated per-event classes (D-6).
        Event::listen(AssignmentTransitioned::class, [CreateBoardCard::class, 'handle']);
        Event::listen(AssignmentTransitioned::class, [BoardAutomationListener::class, 'handle']);
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
