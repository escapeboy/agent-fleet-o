<?php

namespace App\Mcp\Tools\Tool;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ToolProfileListTool extends Tool
{
    protected string $name = 'tool_profile_list';

    protected string $description = 'List available tool profiles that can be assigned to agents. Each profile defines allowed tool groups and max tool count.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'profile' => $schema->string()
                ->description('Optional: return only a specific profile by key (e.g. researcher, executor, communicator, analyst, admin, minimal)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $profiles = config('tool_profiles.profiles', []);

        if ($profileKey = $request->get('profile')) {
            if (! isset($profiles[$profileKey])) {
                return Response::text(json_encode([
                    'error' => "Profile '{$profileKey}' not found.",
                    'available' => array_keys($profiles),
                ]));
            }

            return Response::text(json_encode([
                'profile' => $profileKey,
                ...$profiles[$profileKey],
            ]));
        }

        $result = [];
        foreach ($profiles as $key => $config) {
            $result[] = [
                'profile' => $key,
                'label' => $config['label'],
                'description' => $config['description'],
                'tool_groups' => $config['tool_groups'],
                'max_tools' => $config['max_tools'],
            ];
        }

        return Response::text(json_encode([
            'count' => count($result),
            'profiles' => $result,
        ]));
    }
}
