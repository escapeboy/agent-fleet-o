<?php

namespace App\Mcp\Concerns;

use App\Mcp\Methods\CacheableListTools;
use App\Mcp\Methods\MultiRoundTripCallTool;
use App\Mcp\Methods\ServerDiscover;
use App\Mcp\Protocol\ProtocolVersions;

/**
 * Wires a Laravel MCP server to speak both the 2026-07-28 revision and the
 * older initialize/session revisions.
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

        $this->addMethod('server/discover', ServerDiscover::class);
        $this->addMethod('tools/list', CacheableListTools::class);
        $this->addMethod('tools/call', MultiRoundTripCallTool::class);
    }
}
