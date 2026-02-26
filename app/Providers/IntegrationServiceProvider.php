<?php

namespace App\Providers;

use App\Domain\Integration\Services\IntegrationManager;
use Illuminate\Support\ServiceProvider;

class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/integrations.php',
            'integrations'
        );

        $this->app->singleton(IntegrationManager::class, function ($app) {
            return new IntegrationManager($app);
        });
    }
}
