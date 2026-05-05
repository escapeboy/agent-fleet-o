<?php

namespace App\Mcp\Tools\Auth;

use App\Domain\Shared\Services\SocialAccountService;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class SocialAccountUnlinkTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'social_account_unlink';

    protected string $description = 'Unlink a social login provider from the current user account. Fails if this is the only login method and no password is set.';

    public function __construct(private readonly SocialAccountService $socialAccountService) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'provider' => $schema->string()->enum(['google', 'github', 'linkedin-openid', 'x', 'apple'])
                ->description('The social provider to disconnect.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = auth()->user();

        if (! $user) {
            return $this->permissionDeniedError('Not authenticated.');
        }

        $provider = $request->get('provider');

        $success = $this->socialAccountService->unlink($user, $provider);

        if (! $success) {
            return $this->failedPreconditionError('Cannot disconnect the only login method. Set a password first.');
        }

        return Response::text(json_encode(['success' => true, 'provider' => $provider]));
    }
}
