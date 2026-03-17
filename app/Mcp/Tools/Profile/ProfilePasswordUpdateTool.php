<?php

namespace App\Mcp\Tools\Profile;

use App\Actions\Fortify\UpdateUserPassword;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ProfilePasswordUpdateTool extends Tool
{
    protected string $name = 'profile_password_update';

    protected string $description = 'Update the current user\'s password. Provide current_password if the user already has a password set; omit it to set a password for the first time (social-only accounts).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'current_password' => $schema->string('Current password (required if user already has a password)')->nullable(),
            'password' => $schema->string('New password'),
            'password_confirmation' => $schema->string('New password confirmation (must match password)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = auth()->user();

        if (! $user) {
            return Response::error('Not authenticated.');
        }

        $newPassword = $request->get('password');
        $confirmation = $request->get('password_confirmation');

        if ($user->password !== null) {
            // Change existing password — use Fortify action (validates current_password)
            if (! $request->get('current_password')) {
                return Response::error('current_password is required to change an existing password.');
            }

            try {
                app(UpdateUserPassword::class)->update($user, [
                    'current_password' => $request->get('current_password'),
                    'password' => $newPassword,
                    'password_confirmation' => $confirmation,
                ]);
            } catch (ValidationException $e) {
                return Response::error('Validation failed: '.implode(', ', array_merge(...array_values($e->errors()))));
            }
        } else {
            // Set initial password (social-only account)
            try {
                Validator::make([
                    'password' => $newPassword,
                    'password_confirmation' => $confirmation,
                ], [
                    'password' => ['required', 'string', Password::default(), 'confirmed'],
                ])->validate();
            } catch (ValidationException $e) {
                return Response::error('Validation failed: '.implode(', ', array_merge(...array_values($e->errors()))));
            }

            $user->forceFill(['password' => Hash::make($newPassword)])->save();
        }

        return Response::text(json_encode(['success' => true]));
    }
}
