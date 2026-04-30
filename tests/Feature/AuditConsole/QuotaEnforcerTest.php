<?php

namespace Tests\Feature\AuditConsole;

use FleetQ\BorunaAudit\Exceptions\BorunaQuotaExceeded;
use FleetQ\BorunaAudit\Services\QuotaEnforcer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class QuotaEnforcerTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = 'quota-test-tenant';

    protected function tearDown(): void
    {
        Redis::del("boruna:quota:{$this->tenantId}:".now()->format('Y-m'));
        parent::tearDown();
    }

    public function test_under_quota_increments_and_returns(): void
    {
        DB::table('boruna_tenant_settings')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'team_id' => $this->tenantId,
            'quota_per_month' => 100,
            'enabled' => true,
            'shadow_mode' => true,
            'retention_days' => 90,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enforcer = $this->app->make(QuotaEnforcer::class);
        $enforcer->checkAndIncrement($this->tenantId);

        $usage = $enforcer->usage($this->tenantId);
        $this->assertEquals(1, $usage['used']);
    }

    public function test_at_quota_limit_throws_exception(): void
    {
        DB::table('boruna_tenant_settings')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'team_id' => $this->tenantId,
            'quota_per_month' => 3,
            'enabled' => true,
            'shadow_mode' => true,
            'retention_days' => 90,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Redis::set("boruna:quota:{$this->tenantId}:".now()->format('Y-m'), 3);

        $enforcer = $this->app->make(QuotaEnforcer::class);

        $this->expectException(BorunaQuotaExceeded::class);
        $enforcer->checkAndIncrement($this->tenantId);
    }

    public function test_unlimited_quota_never_throws(): void
    {
        // No DB setting = unlimited
        Redis::set("boruna:quota:{$this->tenantId}:".now()->format('Y-m'), 99999);

        $enforcer = $this->app->make(QuotaEnforcer::class);

        // Should not throw
        $enforcer->checkAndIncrement($this->tenantId);
        $this->assertTrue(true);
    }
}
