<?php

namespace App\Mcp\Tools\Credential;

use App\Domain\Credential\Actions\DeleteCredentialAction;
use App\Domain\Credential\Models\Credential;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class CredentialDeleteTool extends Tool
{
    protected string $name = 'credential_delete';

    protected string $description = 'Delete a credential. Also removes it from all project allowed_credential_ids lists.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'credential_id' => $schema->string()
                ->description('The credential UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'credential_id' => 'required|string',
        ]);

        $credential = Credential::find($validated['credential_id']);

        if (! $credential) {
            return Response::error('Credential not found.');
        }

        try {
            app(DeleteCredentialAction::class)->execute($credential);

            return Response::text(json_encode([
                'success' => true,
                'credential_id' => $validated['credential_id'],
                'deleted' => true,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
