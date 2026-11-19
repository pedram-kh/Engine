<?php

declare(strict_types=1);

namespace App\Modules\Strategy;

use App\Modules\Strategy\Features\StrategyEnabled;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

final class StrategyServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Feature::define(StrategyEnabled::NAME, StrategyEnabled::default());
    }
}
