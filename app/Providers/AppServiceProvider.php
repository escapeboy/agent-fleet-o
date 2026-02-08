<?php

namespace App\Providers;

use App\Domain\Audit\Listeners\LogExperimentTransition;
use App\Domain\Budget\Listeners\PauseOnBudgetExceeded;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Listeners\DispatchNextStageJob;
use App\Domain\Experiment\Listeners\NotifyOnCriticalTransition;
use App\Domain\Experiment\Listeners\RecordTransitionMetrics;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Community edition: all authenticated users have full access
        Gate::define('manage-team', fn ($user) => true);
        Gate::define('edit-content', fn ($user) => true);
        Gate::define('delete-team', fn ($user) => true);

        // Domain event listeners
        Event::listen(ExperimentTransitioned::class, DispatchNextStageJob::class);
        Event::listen(ExperimentTransitioned::class, RecordTransitionMetrics::class);
        Event::listen(ExperimentTransitioned::class, NotifyOnCriticalTransition::class);
        Event::listen(ExperimentTransitioned::class, PauseOnBudgetExceeded::class);
        Event::listen(ExperimentTransitioned::class, LogExperimentTransition::class);
    }
}
