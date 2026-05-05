<?php

namespace FleetQ\BorunaAudit;

use FleetQ\BorunaAudit\Services\BundleStorage;
use FleetQ\BorunaAudit\Services\BundleVerifier;
use FleetQ\BorunaAudit\Services\FeatureFlagResolver;
use FleetQ\BorunaAudit\Services\QuotaEnforcer;
use FleetQ\BorunaAudit\Services\WorkflowRunner;
use Illuminate\Support\ServiceProvider;

class BorunaAuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Config lives in the host app's config/boruna_audit.php (not the package).
        // Migrations live in the host app's database/migrations/ (not the package).
        // The package only registers services.

        $this->app->singleton(BundleStorage::class);
        $this->app->singleton(WorkflowRunner::class);
        $this->app->singleton(BundleVerifier::class);
        $this->app->singleton(QuotaEnforcer::class);
        $this->app->singleton(FeatureFlagResolver::class);
    }

    public function boot(): void
    {
        // No publishable assets — config and migrations ship with the host app.
    }
}
