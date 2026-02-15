<?php

namespace App\Mcp\Tools\Credential;

use App\Domain\Credential\Actions\UpdateCredentialAction;
use App\Domain\Credential\Models\Credential;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CredentialUpdateTool extends Tool
{
    protected string $name = 'credential_update';

    protected string $description = 'Update an existing credential metadata. Only provided fields will be changed. Use credential rotation to change secrets.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'credential_id' => $schema->string()
                ->description('The credential UUID')
                ->required(),
            'name' => $schema->string()
                ->description('New credential name'),
            'description' => $schema->string()
                ->description('New credential description'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'credential_id' => 'required|string',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
        ]);

        $credential = Credential::find($validated['credential_id']);

        if (! $credential) {
            return Response::error('Credential not found.');
        }

        $hasUpdates = ($validated['name'] ?? null) !== null || ($validated['description'] ?? null) !== null;

        if (! $hasUpdates) {
            return Response::error('No fields to update. Provide at least one of: name, description.');
        }

        try {
            $result = app(UpdateCredentialAction::class)->execute(
                credential: $credential,
                name: $validated['name'] ?? null,
                description: $validated['description'] ?? null,
            );

            return Response::text(json_encode([
                'success' => true,
                'credential_id' => $result->id,
                'name' => $result->name,
                'updated_fields' => array_keys(array_filter([
                    'name' => $validated['name'] ?? null,
                    'description' => $validated['description'] ?? null,
                ], fn ($v) => $v !== null)),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
