<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\Observability\Alerts\AlertEvaluator;
use App\Infrastructure\Observability\Alerts\AlertRules;
use App\Infrastructure\Observability\Alerts\PlatformAlertTriggered;
use App\Infrastructure\Observability\Prometheus\MetricEmitter;
use App\Infrastructure\Observability\Prometheus\PrometheusRegistry;
use App\Infrastructure\Observability\Prometheus\TopNTeamLabeller;
use App\Infrastructure\Telemetry\Sentry\ErrorCaptured;
use App\Infrastructure\Telemetry\Sentry\FingerprintResolver;
use App\Infrastructure\Telemetry\Sentry\SentryContext;
use App\Infrastructure\Telemetry\Sentry\SentryEventCapturer;
use App\Infrastructure\Telemetry\Sentry\SentryUrlBuilder;
use App\Listeners\Alerts\SendAlertEmail;
use App\Listeners\Observability\RecordPrometheusOnErrorCaptured;
use App\Listeners\Observability\RecordPrometheusOnJobFailed;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;

/**
 * Wires the observability primitives:
 *   - SentryContext / FingerprintResolver / SentryUrlBuilder as singletons.
 *   - SentryEventCapturer wired against the active Sentry Hub.
 *
 * Prometheus / Health bindings are added by the subsequent sprints; this
 * provider grows incrementally but stays the single observability binding
 * surface (no auto-wired magic, easy to audit).
 */
final class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/observability.php', 'observability');

        $this->app->singleton(SentryContext::class, fn () => new SentryContext);

        $this->app->singleton(FingerprintResolver::class, fn () => new FingerprintResolver);

        $this->app->singleton(SentryUrlBuilder::class, function ($app): SentryUrlBuilder {
            return new SentryUrlBuilder($app->make('config'));
        });

        // Bind Sentry HubInterface to the active hub. sentry-laravel registers
        // its own hub via its ServiceProvider, so we just resolve it lazily —
        // this lets us boot in environments without SENTRY_LARAVEL_DSN (Hub
        // returns a no-op when DSN is absent).
        $this->app->singleton(HubInterface::class, function (): HubInterface {
            return SentrySdk::getCurrentHub();
        });

        $this->app->singleton(SentryEventCapturer::class, function ($app): SentryEventCapturer {
            return new SentryEventCapturer(
                context: $app->make(SentryContext::class),
                fingerprinter: $app->make(FingerprintResolver::class),
                hub: $app->make(HubInterface::class),
                events: $app->make(Dispatcher::class),
            );
        });

        // ----- Prometheus -----
        $this->app->singleton(PrometheusRegistry::class, function ($app): PrometheusRegistry {
            return new PrometheusRegistry($app->make('config'));
        });

        $this->app->singleton(TopNTeamLabeller::class, function ($app): TopNTeamLabeller {
            return new TopNTeamLabeller(
                config: $app->make('config'),
                redis: $app->make('redis'),
            );
        });

        $this->app->singleton(MetricEmitter::class, function ($app): MetricEmitter {
            return new MetricEmitter(
                registry: $app->make(PrometheusRegistry::class),
                teamLabeller: $app->make(TopNTeamLabeller::class),
                config: $app->make('config'),
            );
        });

        // ----- Alerting -----
        $this->app->singleton(AlertRules::class, function ($app): AlertRules {
            return new AlertRules($app->make('config'));
        });

        $this->app->singleton(AlertEvaluator::class, function ($app): AlertEvaluator {
            return new AlertEvaluator(
                rules: $app->make(AlertRules::class),
                config: $app->make('config'),
                events: $app->make(Dispatcher::class),
                http: $app->make(HttpFactory::class),
            );
        });
    }

    public function boot(): void
    {
        // Wire Prometheus listeners.
        Event::listen(ErrorCaptured::class, RecordPrometheusOnErrorCaptured::class);
        Event::listen(JobFailed::class, RecordPrometheusOnJobFailed::class);

        // Wire alert dispatcher.
        Event::listen(PlatformAlertTriggered::class, SendAlertEmail::class);
    }
}
