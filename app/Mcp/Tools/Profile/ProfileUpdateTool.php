<?php

namespace App\Mcp\Tools\Profile;

use App\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ProfileUpdateTool extends Tool
{
    protected string $name = 'profile_update';

    protected string $description = 'Update the current user\'s name and/or email address.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string('New display name (1–255 characters)'),
            'email' => $schema->string('New email address'),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = auth()->user();

        if (! $user) {
            return Response::error('Not authenticated.');
        }

        $input = [
            'name' => $request->get('name', $user->name),
            'email' => $request->get('email', $user->email),
        ];

        try {
            app(UpdateUserProfileInformation::class)->update($user, $input);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return Response::error('Validation failed: ' . implode(', ', array_merge(...array_values($e->errors()))));
        }

        return Response::text(json_encode([
            'success' => true,
            'name' => $user->fresh()->name,
            'email' => $user->fresh()->email,
        ]));
    }
}
