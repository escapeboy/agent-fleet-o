<?php

namespace App\Mcp\Concerns;

use App\Mcp\Methods\CacheableListTools;
use App\Mcp\Methods\MultiRoundTripCallTool;
use App\Mcp\Methods\RecordingInitialize;
use App\Mcp\Protocol\ProtocolVersions;

/**
 * Wires a Laravel MCP server to speak both the 2026-07-28 revision and the
 * older initialize/session revisions. laravel/mcp 1.0 serves server/discover,
 * _meta negotiation and the SEP-2243 headers itself; FleetQ adds tools/list
 * cache hints and SEP-2322 multi round-trip on tools/call.
 *
 * Call bootDualProtocolFormat() from the server's boot(); it runs before
 * createContext(), so widening $supportedProtocolVersion here is what the
 * advertised version list is built from.
 *
 * @phpstan-require-extends \Laravel\Mcp\Server
 *
 * @mixin \Laravel\Mcp\Server
 */
trait SupportsDualProtocolFormat
{
    protected function bootDualProtocolFormat(): void
    {
        $this->supportedProtocolVersion = ProtocolVersions::SUPPORTED;

        $this->addMethod('initialize', RecordingInitialize::class);
        $this->addMethod('tools/list', CacheableListTools::class);
        $this->addMethod('tools/call', MultiRoundTripCallTool::class);
    }
}
