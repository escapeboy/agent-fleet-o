<?php

namespace App\Mcp\Tools\System;

use App\Domain\System\Services\VersionCheckService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class SystemVersionCheckTool extends Tool
{
    protected string $name = 'system_version_check';

    protected string $description = 'Check the installed FleetQ version and whether a newer release is available on GitHub. Optionally force a fresh check bypassing the 1-hour cache.';

    public function schema(JsonSchema $schema): array
    {
        return [
            $schema->optionalBoolean('force', 'Bypass the cache and fetch the latest version from GitHub immediately.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $service = app(VersionCheckService::class);

        if ($request->get('force')) {
            $service->forceRefresh();
        }

        $info = [
            'installed_version' => $service->getInstalledVersion(),
            'latest_version' => $service->getLatestVersion(),
            'update_available' => $service->isUpdateAvailable(),
            'check_enabled' => $service->isCheckEnabled(),
            'update_info' => $service->getUpdateInfo(),
        ];

        return Response::text(json_encode($info, JSON_PRETTY_PRINT));
    }
}
