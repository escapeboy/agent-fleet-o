<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Monolog\ResettableInterface;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Defensive: reset Monolog handler buffers after each job.
        // StreamHandler (current default) is a no-op. This guards against future
        // adoption of FingersCrossedHandler, which accumulates records indefinitely
        // in long-running Horizon workers (never flushed without an explicit reset).
        // See: https://accesto.com/blog/long-running-php-websocket-server/
        $resetLogger = function (): void {
            $logger = app('log');
            if ($logger instanceof ResettableInterface) {
                $logger->reset();
            }
        };

        Queue::after(fn () => $resetLogger());
        Queue::failing(fn () => $resetLogger());

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            // Community edition: all authenticated users can access Horizon
            return $user !== null;
        });
    }
}
