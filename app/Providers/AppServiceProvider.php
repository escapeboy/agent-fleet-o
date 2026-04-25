<?php

namespace App\Providers;

use App\Domain\Agent\Events\AgentExecuted;
use App\Domain\Agent\Listeners\ProvisionPersonalAgentListener;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\AgentChatProtocol\Events\ChatMessageDispatched;
use App\Domain\AgentChatProtocol\Events\ChatMessageReceived;
use App\Domain\AgentChatProtocol\Listeners\ExecuteAgentOnChatMessage;
use App\Domain\AgentChatProtocol\Listeners\LogProtocolTransaction;
use App\Domain\Audit\Listeners\LogExperimentTransition;
use App\Domain\Audit\Listeners\LogIntegrationExecution;
use App\Domain\Budget\Listeners\PauseOnBudgetExceeded;
use App\Domain\Chatbot\Events\ChatbotResponseApprovedEvent;
use App\Domain\Chatbot\Listeners\CaptureResponseCorrectionListener;
use App\Domain\Chatbot\Listeners\DeliverChatbotWorkflowResultListener;
use App\Domain\Chatbot\Listeners\ExtractChatMemoriesListener;
use App\Domain\Credential\Observers\SecretScanObserver;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Events\StuckPatternDetected;
use App\Domain\Experiment\Listeners\CheckParentExperimentCompletion;
use App\Domain\Experiment\Listeners\CollectWorkflowArtifactsOnCompletion;
use App\Domain\Experiment\Listeners\DispatchNextStageJob;
use App\Domain\Experiment\Listeners\HandleStuckPattern;
use App\Domain\Experiment\Listeners\NotifyOnCriticalTransition;
use App\Domain\Experiment\Listeners\RecordReasoningBankEntry;
use App\Domain\Experiment\Listeners\RecordTransitionMetrics;
use App\Domain\Experiment\Listeners\ResumeParentOnSubWorkflowComplete;
use App\Domain\Integration\Events\IntegrationActionExecuted;
use App\Domain\Memory\Listeners\ExtractFailureLessonListener;
use App\Domain\Memory\Listeners\ExtractSuccessPatternListener;
use App\Domain\Memory\Listeners\FlushAgentMemoryOnCompletion;
use App\Domain\Memory\Listeners\StoreExecutionMemory;
use App\Domain\Memory\Listeners\StoreExperimentLearnings;
use App\Domain\Metrics\Jobs\EvaluateExecutionJob;
use App\Domain\Outbound\Connectors\NotificationConnector;
use App\Domain\Outbound\Connectors\SmtpEmailConnector;
use App\Domain\Outbound\Connectors\WebhookOutboundConnector;
use App\Domain\Outbound\Managers\OutboundConnectorManager;
use App\Domain\Project\Listeners\LogProjectActivity;
use App\Domain\Project\Listeners\NotifyAssistantOnProjectComplete;
use App\Domain\Project\Listeners\NotifyDependentsOnRunComplete;
use App\Domain\Project\Listeners\SyncProjectStatusOnRunComplete;
use App\Domain\Shared\Events\TeamMemberRemoved;
use App\Domain\Shared\Listeners\RevokeTeamMemberAccess;
use App\Domain\Shared\Services\DeploymentMode;
use App\Domain\Shared\Services\NavigationRegistry;
use App\Domain\Shared\Services\PluginRegistry;
use App\Domain\Signal\Connectors\ApiPollingConnector;
use App\Domain\Signal\Connectors\BugReportConnector;
use App\Domain\Signal\Connectors\CalendarConnector;
use App\Domain\Signal\Connectors\ClearCueConnector;
use App\Domain\Signal\Connectors\ConfluenceConnector;
use App\Domain\Signal\Connectors\DatadogAlertConnector;
use App\Domain\Signal\Connectors\DiscordWebhookConnector;
use App\Domain\Signal\Connectors\GitHubIssuesConnector;
use App\Domain\Signal\Connectors\GitHubWebhookConnector;
use App\Domain\Signal\Connectors\GitHubWikiConnector;
use App\Domain\Signal\Connectors\HttpMonitorConnector;
use App\Domain\Signal\Connectors\ImapConnector;
use App\Domain\Signal\Connectors\JiraConnector;
use App\Domain\Signal\Connectors\LinearConnector;
use App\Domain\Signal\Connectors\ManualSignalConnector;
use App\Domain\Signal\Connectors\MatrixConnector;
use App\Domain\Signal\Connectors\NotionConnector;
use App\Domain\Signal\Connectors\PagerDutyConnector;
use App\Domain\Signal\Connectors\RssConnector;
use App\Domain\Signal\Connectors\ScreenpipeConnector;
use App\Domain\Signal\Connectors\SearxngConnector;
use App\Domain\Signal\Connectors\SentryAlertConnector;
use App\Domain\Signal\Connectors\SignalProtocolConnector;
use App\Domain\Signal\Connectors\SlackWebhookConnector;
use App\Domain\Signal\Connectors\SupabaseWebhookConnector;
use App\Domain\Signal\Connectors\TelegramSignalConnector;
use App\Domain\Signal\Connectors\WebhookConnector;
use App\Domain\Signal\Connectors\WhatsAppWebhookConnector;
use App\Domain\Signal\Events\SignalIngested;
use App\Domain\Signal\Events\SignalStatusChanged;
use App\Domain\Signal\Listeners\InferIncomingSignalIntent;
use App\Domain\Signal\Listeners\NotifyOnCriticalBugReport;
use App\Domain\Signal\Listeners\NotifyOnSignalStatusChange;
use App\Domain\Signal\Listeners\SyncSignalStatusOnExperimentComplete;
use App\Domain\Signal\Services\SignalConnectorRegistry;
use App\Domain\Skill\Listeners\DispatchEvolutionAnalysisListener;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;
use App\Domain\Tool\Services\McpHandleRegistry;
use App\Domain\Webhook\Listeners\SendWebhookOnExperimentTransition;
use App\Domain\Webhook\Listeners\SendWebhookOnProjectRunComplete;
use App\Domain\Website\Drivers\VercelDeploymentDriver;
use App\Domain\Website\Drivers\WebsiteDeploymentDriverRegistry;
use App\Domain\Website\Drivers\ZipDeploymentDriver;
use App\Domain\Website\Models\WebsitePage;
use App\Domain\Website\Observers\WebsitePageObserver;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Domain\Workflow\Services\WorkflowNodeRegistry;
use App\Infrastructure\AI\Middleware\BudgetEnforcement;
use App\Infrastructure\AI\Middleware\IdempotencyCheck;
use App\Infrastructure\AI\Middleware\RateLimiting;
use App\Infrastructure\AI\Middleware\SchemaValidation;
use App\Infrastructure\AI\Middleware\SemanticCache;
use App\Infrastructure\AI\Middleware\UsageTracking;
use App\Infrastructure\Auth\CompatibleSanctumGuard;
use App\Infrastructure\Auth\ScopedPersonalAccessToken;
use App\Infrastructure\Bridge\HandleBridgeRelayResponse;
use App\Infrastructure\Mail\TeamAwareMailChannel;
use App\Infrastructure\Telemetry\AttributeRedactor;
use App\Infrastructure\Telemetry\TenantTracerProviderFactory;
use App\Infrastructure\Telemetry\TracerProvider as FleetTracerProvider;
use App\Livewire\Hooks\PluginDispatchHook;
use App\Mcp\DeadlineContext;
use App\Mcp\ErrorClassifier;
use App\Mcp\Listeners\McpAppsCapabilityListener;
use App\Models\User;
use Barsy\Events\ChatMessageCompleted;
use Carbon\CarbonInterval;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\SecuritySchemes\OAuthFlow;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\RequestGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Mcp\Events\SessionInitialized;
use Laravel\Passport\Passport;
use Laravel\Reverb\Events\MessageReceived;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use SocialiteProviders\Apple\Provider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DeploymentMode::class, fn () => new DeploymentMode);

        // Lazy MCP stdio handle registry — one instance per request/job lifecycle
        $this->app->singleton(McpHandleRegistry::class);

        // MCP structured error classifier (singleton so plugins can register custom mappings)
        $this->app->singleton(ErrorClassifier::class);

        // MCP deadline context — request-scoped; nested tool calls inherit the same instance
        $this->app->singleton(DeadlineContext::class);

        // OpenTelemetry tracer provider — no-op tracer when OTEL_ENABLED=false (zero overhead)
        $this->app->singleton(FleetTracerProvider::class);
        // Per-team tracer factory — in-process cache of tenant-scoped providers so
        // changes to team.settings.observability take effect via forget() without a worker restart.
        $this->app->singleton(TenantTracerProviderFactory::class);
        $this->app->singleton(AttributeRedactor::class);
        $this->app->terminating(function () {
            if (app()->resolved(FleetTracerProvider::class)) {
                app(FleetTracerProvider::class)->shutdown();
            }
            // Clear request-scoped MCP deadline so Horizon workers don't inherit
            // a stale deadline from a prior job's exception path.
            if (app()->resolved(DeadlineContext::class)) {
                app(DeadlineContext::class)->clear();
            }
        });

        // Plugin extension points
        $this->app->singleton(PluginRegistry::class, fn () => new PluginRegistry);
        $this->app->singleton(NavigationRegistry::class, fn () => new NavigationRegistry);
        $this->app->singleton(WorkflowNodeRegistry::class, fn () => new WorkflowNodeRegistry);

        // Website deployment drivers — plugins register additional providers via the registry
        $this->app->singleton(WebsiteDeploymentDriverRegistry::class, function ($app) {
            $registry = new WebsiteDeploymentDriverRegistry;
            $registry->register($app->make(ZipDeploymentDriver::class));
            $registry->register($app->make(VercelDeploymentDriver::class));

            return $registry;
        });

        // Accumulator for plugin-contributed MCP tool class names
        $this->app->instance('fleet.mcp.tool_classes', []);

        // Tag all built-in signal connectors so plugins and the registry can discover them
        $this->app->tag([
            WebhookConnector::class,
            RssConnector::class,
            ManualSignalConnector::class,
            ImapConnector::class,
            ApiPollingConnector::class,
            TelegramSignalConnector::class,
            SlackWebhookConnector::class,
            DiscordWebhookConnector::class,
            WhatsAppWebhookConnector::class,
            GitHubWebhookConnector::class,
            GitHubIssuesConnector::class,
            LinearConnector::class,
            JiraConnector::class,
            PagerDutyConnector::class,
            SentryAlertConnector::class,
            DatadogAlertConnector::class,
            ClearCueConnector::class,
            MatrixConnector::class,
            SignalProtocolConnector::class,
            HttpMonitorConnector::class,
            CalendarConnector::class,
            SupabaseWebhookConnector::class,
            SearxngConnector::class,
            ScreenpipeConnector::class,
            NotionConnector::class,
            ConfluenceConnector::class,
            GitHubWikiConnector::class,
            BugReportConnector::class,
        ], 'fleet.signal.connectors');

        // Bind SignalConnectorRegistry to resolve all tagged signal connectors
        $this->app->singleton(
            SignalConnectorRegistry::class,
            fn ($app) => new SignalConnectorRegistry($app->tagged('fleet.signal.connectors')),
        );

        // Tag core outbound connectors (plugins extend via OutboundConnectorManager::extend())
        $this->app->tag([
            SmtpEmailConnector::class,
            WebhookOutboundConnector::class,
            NotificationConnector::class,
        ], 'fleet.outbound.connectors');

        // Outbound connector manager — resolves connectors by channel name
        $this->app->singleton(OutboundConnectorManager::class);

        // Tag all built-in AI gateway middleware (plugins can prepend their own)
        $this->app->tag([
            RateLimiting::class,
            BudgetEnforcement::class,
            IdempotencyCheck::class,
            SemanticCache::class,
            SchemaValidation::class,
            UsageTracking::class,
        ], 'fleet.ai.middleware');

        // Replace the default MailChannel with our team-aware variant that applies
        // the active email theme to all system notification emails.
        $this->app->bind(MailChannel::class, TeamAwareMailChannel::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Override Sanctum's guard driver to also accept users whose model uses
        // Passport's HasApiTokens trait (for MCP OAuth2 co-existence). The default
        // Sanctum guard rejects such users, breaking all /api/v1/ token authentication.
        // Using Auth::resolved() ensures our callback runs AFTER Sanctum registers its
        // own version, so ours takes precedence (last Auth::extend call wins).
        Auth::resolved(function ($auth) {
            $auth->extend('sanctum', function ($app, $name, array $config) use ($auth) {
                return tap(new RequestGuard(
                    new CompatibleSanctumGuard(
                        $auth,
                        config('sanctum.expiration'),
                        $config['provider'] ?? null,
                        config('sanctum.last_used_at', true),
                    ),
                    $app['request'],
                    $auth->createUserProvider($config['provider'] ?? null),
                ), function ($guard) {
                    app()->refresh('request', $guard, 'setRequest');
                });
            });
        });

        // Configure Sanctum to use our ScopedPersonalAccessToken model so that tokens
        // retrieved via findToken() implement ScopeAuthorizable and can be stored in
        // Passport's typed ?ScopeAuthorizable $accessToken property without a TypeError.
        Sanctum::usePersonalAccessTokenModel(ScopedPersonalAccessToken::class);

        // Passport OAuth2 — used for MCP server authentication (Authorization Code + PKCE)
        // 24-hour TTL: MCP Desktop clients (Claude.ai, Cursor) keep sessions open all day.
        // Refresh tokens (30-day TTL) allow silent renewal when the access token expires.
        Passport::tokensExpireIn(CarbonInterval::hours(24));
        Passport::refreshTokensExpireIn(CarbonInterval::days(30));
        Passport::tokensCan(['mcp:use' => 'Use the FleetQ MCP server']);
        Passport::authorizationView('mcp.authorize');
        // OAuth 2.1 mandates PKCE — Passport enforces it for public clients when
        // code_challenge is submitted. The registration endpoint registers clients
        // as non-confidential (confidential: false), which requires PKCE automatically.

        // Named rate limiters for OAuth endpoints.
        // /oauth/register: 20 per hour per IP (RFC 7591 recommendation).
        // /oauth/token: uses Passport's built-in 'throttle' (60/min); 'oauth-token' named limiter
        //   is available if routes are overridden in cloud to apply a stricter hourly cap.
        RateLimiter::for('oauth-register', fn (Request $request) => Limit::perHour(20)->by($request->ip()),
        );
        RateLimiter::for('oauth-token', fn (Request $request) => Limit::perHour(60)->by($request->ip()),
        );

        // Force HTTPS when APP_URL uses https (e.g. behind OrbStack / reverse proxy)
        if (str_starts_with(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Polymorphic morph map for credential creator tracking
        Relation::morphMap([
            'user' => User::class,
            'agent' => Agent::class,
        ]);

        // Secret scanner: detect accidentally embedded secrets in free-text fields
        // on Agent, Skill, and WorkflowNode models. Each model declares $scannableFields.
        Agent::observe(SecretScanObserver::class);
        Skill::observe(SecretScanObserver::class);
        WorkflowNode::observe(SecretScanObserver::class);

        // Website widget cache invalidation: bump website.content_version
        // whenever a page is saved or deleted so cached widget output becomes
        // unreachable (old cache keys are abandoned, TTL sweeps them up).
        WebsitePage::observe(WebsitePageObserver::class);

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

        // Plugin lifecycle hook: allows plugins to react to Livewire component events
        Livewire::componentHook(PluginDispatchHook::class);

        // Boot external plugin providers (e.g. Barsy\Plugins\BarsyChatbotPlugin).
        // Configured via FLEET_EXTERNAL_PLUGIN_PROVIDERS env var — no changes to
        // this codebase required. The external package handles its own autoloading.
        foreach (config('plugins.external_providers', []) as $providerClass) {
            if (class_exists($providerClass)) {
                $this->app->register($providerClass);
            }
        }

        // Auto-provision personal agent for new users
        Event::listen(Registered::class, ProvisionPersonalAgentListener::class);

        // Apple Sign In via SocialiteProviders community package
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('apple', Provider::class);
        });

        // Bridge relay: forward Reverb client-relay.* whispers into Redis stream
        Event::listen(MessageReceived::class, HandleBridgeRelayResponse::class);

        // MCP Apps: record per-session capability flag on initialize handshake
        Event::listen(SessionInitialized::class, McpAppsCapabilityListener::class);

        // Integration audit trail: log every executed driver action
        Event::listen(
            IntegrationActionExecuted::class,
            LogIntegrationExecution::class,
        );

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

        // Agent Chat Protocol: execute agent on inbound chat + log protocol transactions
        Event::listen(
            ChatMessageReceived::class,
            ExecuteAgentOnChatMessage::class,
        );
        Event::listen(
            ChatMessageReceived::class,
            [LogProtocolTransaction::class, 'handleReceived'],
        );
        Event::listen(
            ChatMessageDispatched::class,
            [LogProtocolTransaction::class, 'handleDispatched'],
        );

        // Sub-workflow node: resume parent workflow step when sub-workflow experiment completes
        Event::listen(ExperimentTransitioned::class, ResumeParentOnSubWorkflowComplete::class);

        // Memory: extract learnings from completed experiments
        Event::listen(ExperimentTransitioned::class, StoreExperimentLearnings::class);

        // ReasoningBank: record strategy embeddings from completed experiments
        Event::listen(ExperimentTransitioned::class, RecordReasoningBankEntry::class);

        // Memory: extract failure lesson when experiment enters a failed state
        Event::listen(ExperimentTransitioned::class, ExtractFailureLessonListener::class);

        // Memory: extract success pattern when experiment reaches Completed
        Event::listen(ExperimentTransitioned::class, ExtractSuccessPatternListener::class);

        // Memory: flush agent memory on workflow experiment completion
        Event::listen(ExperimentTransitioned::class, FlushAgentMemoryOnCompletion::class);

        // Stuck pattern detection: handle detected stuck patterns (notify/pause/kill)
        Event::listen(StuckPatternDetected::class, HandleStuckPattern::class);

        // Chatbot: deliver workflow result to chatbot message on experiment completion
        Event::listen(ExperimentTransitioned::class, DeliverChatbotWorkflowResultListener::class);

        // Chatbot: capture operator corrections as learning entries
        Event::listen(ChatbotResponseApprovedEvent::class, CaptureResponseCorrectionListener::class);

        // Barsy chatbot: extract durable facts from chat exchanges into proposed memories
        Event::listen(ChatMessageCompleted::class, ExtractChatMemoriesListener::class);

        // Skill evolution: analyze completed executions and auto-propose improvements
        Event::listen(AgentExecuted::class, DispatchEvolutionAnalysisListener::class);

        // Bug report signals: notify on critical severity + on status transitions
        Event::listen(SignalIngested::class, NotifyOnCriticalBugReport::class);
        Event::listen(SignalIngested::class, InferIncomingSignalIntent::class);
        Event::listen(SignalStatusChanged::class, NotifyOnSignalStatusChange::class);

        // Bug report delegation: advance signal to review when agent experiment completes
        Event::listen(ExperimentTransitioned::class, SyncSignalStatusOnExperimentComplete::class);

        // Team member removal: revoke tokens + pause active experiments
        Event::listen(TeamMemberRemoved::class, RevokeTeamMemberAccess::class);

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
            // Bearer token auth (Sanctum) — used by all /api/v1/ endpoints
            $openApi->secure(
                SecurityScheme::http('bearer', 'token'),
            );

            // OAuth2 Authorization Code + PKCE — required for ChatGPT Actions and
            // any OAuth2-capable client. Describes the same endpoints as the MCP
            // OAuth discovery documents (/.well-known/oauth-authorization-server).
            $openApi->components->securitySchemes['oauth2'] = SecurityScheme::oauth2()
                ->flow('authorizationCode', function (OAuthFlow $flow) {
                    $flow->authorizationUrl = url('/oauth/authorize');
                    $flow->tokenUrl = url('/oauth/token');
                    $flow->scopes = ['mcp:use' => 'Full access to the FleetQ MCP server and REST API'];
                });
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
