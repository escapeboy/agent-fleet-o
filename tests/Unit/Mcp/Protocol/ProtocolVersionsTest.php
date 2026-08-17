<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Protocol;

use App\Mcp\Protocol\ProtocolVersions;
use PHPUnit\Framework\TestCase;

class ProtocolVersionsTest extends TestCase
{
    public function test_newest_supported_version_is_advertised_first(): void
    {
        $this->assertSame(ProtocolVersions::V2026_07_28, ProtocolVersions::SUPPORTED[0]);
    }

    public function test_legacy_versions_remain_supported_during_transition(): void
    {
        foreach ([
            ProtocolVersions::V2025_11_25,
            ProtocolVersions::V2025_06_18,
            ProtocolVersions::V2025_03_26,
            ProtocolVersions::V2024_11_05,
        ] as $version) {
            $this->assertTrue(ProtocolVersions::supports($version), $version.' must stay supported');
        }
    }

    public function test_unknown_and_null_versions_are_unsupported(): void
    {
        $this->assertFalse(ProtocolVersions::supports('1999-01-01'));
        $this->assertFalse(ProtocolVersions::supports(null));
        $this->assertFalse(ProtocolVersions::supports(''));
    }

    public function test_only_2026_07_28_and_newer_are_stateless(): void
    {
        $this->assertTrue(ProtocolVersions::isStateless(ProtocolVersions::V2026_07_28));
        $this->assertTrue(ProtocolVersions::isStateless('2027-01-01'));

        $this->assertFalse(ProtocolVersions::isStateless(ProtocolVersions::V2025_11_25));
        $this->assertFalse(ProtocolVersions::isStateless(ProtocolVersions::V2024_11_05));
    }

    public function test_cache_hints_follow_the_same_floor(): void
    {
        $this->assertTrue(ProtocolVersions::supportsCacheHints(ProtocolVersions::V2026_07_28));
        $this->assertFalse(ProtocolVersions::supportsCacheHints(ProtocolVersions::V2025_11_25));
    }

    public function test_fallback_matches_the_transport_spec_default(): void
    {
        $this->assertSame('2025-03-26', ProtocolVersions::FALLBACK);
    }
}
