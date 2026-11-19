<?php

namespace App\Http\Controllers;

use App\Mcp\Services\McpToolCatalog;
use Illuminate\Contracts\View\View;

/**
 * Public, indexable catalog of the MCP tools FleetQ exposes (GET /mcp-tools).
 */
class McpToolCatalogController extends Controller
{
    public function __invoke(McpToolCatalog $catalog): View
    {
        return view('mcp-tools.index', [
            'groups' => $catalog->groups(),
            'total' => $catalog->total(),
        ]);
    }
}
