<?php

namespace App\Providers;

use App\Domain\Audit\Listeners\LogExperimentTransition;
use App\Domain\Budget\Listeners\PauseOnBudgetExceeded;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Listeners\CollectWorkflowArtifactsOnCompletion;
use App\Domain\Experiment\Listeners\DispatchNextStageJob;
use App\Domain\Experiment\Listeners\NotifyOnCriticalTransition;
use App\Domain\Experiment\Listeners\RecordTransitionMetrics;
use App\Domain\Project\Listeners\LogProjectActivity;
use App\Domain\Project\Listeners\NotifyDependentsOnRunComplete;
use App\Domain\Project\Listeners\SyncProjectStatusOnRunComplete;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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

        // Workflow artifact collection (must fire BEFORE SyncProjectStatusOnRunComplete
        // so that artifacts exist when DeliverWorkflowResultsJob dispatches)
        Event::listen(ExperimentTransitioned::class, CollectWorkflowArtifactsOnCompletion::class);

        // Project listeners (syncs run status when experiment completes)
        Event::listen(ExperimentTransitioned::class, SyncProjectStatusOnRunComplete::class);
        Event::listen(ExperimentTransitioned::class, LogProjectActivity::class);
        Event::listen(ExperimentTransitioned::class, NotifyDependentsOnRunComplete::class);

        // API rate limiting
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user();

            if (! $user) {
                return Limit::perMinute(10)->by($request->ip());
            }

            return Limit::perMinute(60)->by($user->currentAccessToken()?->id ?? $user->id);
        });

        // Scramble API documentation — only document /api/v1 routes
        Scramble::configure()
            ->routes(fn (Route $route) => Str::startsWith($route->uri(), 'api/v1/'));

        Scramble::afterOpenApiGenerated(function (OpenApi $openApi) {
            $openApi->secure(
                SecurityScheme::http('bearer', 'token'),
            );
        });
    }
}
