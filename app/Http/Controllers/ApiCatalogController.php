<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Serves the RFC 9727 API catalog so agents can discover the REST API and the
 * MCP endpoint without scraping the docs site.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9727
 */
class ApiCatalogController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'linkset' => [
                [
                    'anchor' => url('/api/v1'),
                    'service-desc' => [
                        [
                            'href' => url('/docs/api.json'),
                            'type' => 'application/vnd.oai.openapi+json;version=3.1',
                            'title' => 'FleetQ REST API v1 — OpenAPI 3.1 description',
                        ],
                    ],
                    'service-doc' => [
                        [
                            'href' => url('/docs/api'),
                            'type' => 'text/html',
                            'title' => 'FleetQ REST API v1 — reference documentation',
                        ],
                    ],
                    'status' => [
                        [
                            'href' => url('/api/v1/health'),
                            'type' => 'application/json',
                            'title' => 'FleetQ platform health',
                        ],
                    ],
                ],
                [
                    'anchor' => url('/mcp'),
                    'service-doc' => [
                        [
                            'href' => url('/docs/mcp-server'),
                            'type' => 'text/html',
                            'title' => 'FleetQ MCP server — connection guide',
                        ],
                    ],
                    'describedby' => [
                        [
                            'href' => url('/.well-known/fleetq'),
                            'type' => 'application/json',
                            'title' => 'FleetQ MCP discovery document',
                        ],
                    ],
                ],
            ],
        ], 200, [
            'Content-Type' => 'application/linkset+json',
        ]);
    }
}
