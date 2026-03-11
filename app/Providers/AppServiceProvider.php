<?php

namespace App\Providers;

use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Audit\Listeners\LogExperimentTransition;
use App\Domain\Budget\Listeners\PauseOnBudgetExceeded;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Listeners\CheckParentExperimentCompletion;
use App\Domain\Experiment\Listeners\CollectWorkflowArtifactsOnCompletion;
use App\Domain\Experiment\Listeners\DispatchNextStageJob;
use App\Domain\Experiment\Listeners\NotifyOnCriticalTransition;
use App\Domain\Experiment\Listeners\RecordTransitionMetrics;
use App\Domain\Experiment\Listeners\ResumeParentOnSubWorkflowComplete;
use App\Domain\Memory\Listeners\StoreExecutionMemory;
use App\Domain\Memory\Listeners\StoreExperimentLearnings;
use App\Domain\Metrics\Jobs\EvaluateExecutionJob;
use App\Domain\Project\Listeners\LogProjectActivity;
use App\Domain\Project\Listeners\NotifyAssistantOnProjectComplete;
use App\Domain\Project\Listeners\NotifyDependentsOnRunComplete;
use App\Domain\Project\Listeners\SyncProjectStatusOnRunComplete;
use App\Domain\Shared\Services\DeploymentMode;
use App\Domain\Skill\Models\SkillExecution;
use App\Domain\Webhook\Listeners\SendWebhookOnExperimentTransition;
use App\Domain\Webhook\Listeners\SendWebhookOnProjectRunComplete;
use App\Infrastructure\Bridge\HandleBridgeRelayResponse;
use App\Infrastructure\Mail\TeamAwareMailChannel;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Reverb\Events\MessageReceived;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DeploymentMode::class, fn () => new DeploymentMode);

        // Replace the default MailChannel with our team-aware variant that applies
        // the active email theme to all system notification emails.
        $this->app->bind(MailChannel::class, TeamAwareMailChannel::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force HTTPS when APP_URL uses https (e.g. behind OrbStack / reverse proxy)
        if (str_starts_with(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Community edition: all authenticated users have full access
        Gate::define('manage-team', fn ($user) => true);
        Gate::define('edit-content', fn ($user) => true);
        Gate::define('delete-team', fn ($user) => true);

        // Deployment mode feature gates
        $mode = app(DeploymentMode::class);
        Gate::define('feature.local_agents', fn ($user) => $mode->isSelfHosted());
        Gate::define('feature.mcp_host_scan', fn ($user) => $mode->isSelfHosted());
        Gate::define('feature.security_policy', fn ($user) => $mode->isSelfHosted());
        Gate::define('feature.built_in_tools', fn ($user) => $mode->isSelfHosted());

        // Blade directives for deployment mode
        Blade::if('cloud', fn () => app(DeploymentMode::class)->isCloud());
        Blade::if('selfhosted', fn () => app(DeploymentMode::class)->isSelfHosted());

        // Bridge relay: forward Reverb client-relay.* whispers into Redis stream
        Event::listen(MessageReceived::class, HandleBridgeRelayResponse::class);

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

        // Delegation loop: notify assistant/Telegram when delegated run completes
        Event::listen(ExperimentTransitioned::class, NotifyAssistantOnProjectComplete::class);

        // Webhook notifications
        Event::listen(ExperimentTransitioned::class, SendWebhookOnExperimentTransition::class);
        Event::listen(ExperimentTransitioned::class, SendWebhookOnProjectRunComplete::class);

        // Sub-experiment orchestration: check parent when child reaches terminal state
        Event::listen(ExperimentTransitioned::class, CheckParentExperimentCompletion::class);

        // Sub-workflow node: resume parent workflow step when sub-workflow experiment completes
        Event::listen(ExperimentTransitioned::class, ResumeParentOnSubWorkflowComplete::class);

        // Memory: extract learnings from completed experiments
        Event::listen(ExperimentTransitioned::class, StoreExperimentLearnings::class);

        // Agent memory: store execution output as memory after completion
        AgentExecution::created(function (AgentExecution $execution) {
            app(StoreExecutionMemory::class)->handle($execution);

            // Quality evaluation: dispatch async job if evaluation is enabled and passes sampling
            if ($execution->status === 'completed' && $execution->agent) {
                $agent = $execution->agent;
                if ($agent->evaluation_enabled && mt_rand(1, 100) <= ($agent->evaluation_sample_rate * 100)) {
                    EvaluateExecutionJob::dispatch('agent', $execution->id);
                }
            }
        });

        // Skill execution quality evaluation
        SkillExecution::created(function (SkillExecution $execution) {
            if ($execution->status === 'completed' && $execution->skill) {
                $skill = $execution->skill;
                if ($skill->evaluation_enabled && mt_rand(1, 100) <= ($skill->evaluation_sample_rate * 100)) {
                    EvaluateExecutionJob::dispatch('skill', $execution->id);
                }
            }
        });

        // Password reset rate limiting (5/min per IP, 3/min per email+IP)
        RateLimiter::for('password-reset', function (Request $request) {
            return [
                Limit::perMinute(5)->by($request->ip()),
                Limit::perMinute(3)->by($request->input('email', '').'|'.$request->ip()),
            ];
        });

        // Branded password reset email
        ResetPassword::toMailUsing(function ($user, string $token) {
            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ], false));
            $expire = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

            return (new MailMessage)
                ->subject('Reset your '.config('app.name').' password')
                ->greeting('Hello, '.$user->name.'!')
                ->line('We received a password reset request for your account.')
                ->action('Reset Password', $url)
                ->line("This link expires in {$expire} minutes.")
                ->line('If you did not request this, you can safely ignore this email.')
                ->salutation('— '.config('app.name'));
        });

        // Public marketplace API rate limiting
        RateLimiter::for('marketplace-public', function (Request $request) {
            return [
                Limit::perMinute(30)->by('min:'.$request->ip()),
                Limit::perHour(500)->by('hour:'.$request->ip()),
            ];
        });

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

        // Allow public access to the API docs (viewApiDocs gate must pass RestrictedDocsAccess middleware)
        Gate::define('viewApiDocs', fn () => true);

        Scramble::afterOpenApiGenerated(function (OpenApi $openApi) {
            $openApi->secure(
                SecurityScheme::http('bearer', 'token'),
            );
        });

        // Serve the OpenAPI JSON spec from a pre-generated file when available.
        // The file is written by `php artisan scramble:export --path=public/api.json`
        // which runs on every deploy and weekly via the scheduler.
        // Falls back to live generation when the file doesn't exist yet.
        Scramble::ignoreDefaultRoutes();
        Scramble::registerUiRoute(path: 'docs/api');
        \Illuminate\Support\Facades\Route::get('docs/api.json', function (Generator $generator) {
            $cached = public_path('api.json');
            if (file_exists($cached)) {
                return response(file_get_contents($cached), 200, ['Content-Type' => 'application/json']);
            }
            $config = Scramble::getGeneratorConfig('default');

            return response()->json($generator($config), options: JSON_PRETTY_PRINT);
        })->middleware(config('scramble.middleware', ['web']))->name('scramble.docs.document');
    }
}
