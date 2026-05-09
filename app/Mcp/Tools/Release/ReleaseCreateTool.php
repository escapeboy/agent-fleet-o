<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Release;

use App\Domain\Release\Actions\CreateReleaseAction;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsDestructive]
#[IsIdempotent]
class ReleaseCreateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'release_create';

    protected string $description = 'Create a new draft release. Idempotent on (team, slug, version).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Release name')->required(),
            'version' => $schema->string()->description('Version label (free-form, e.g. v1, 2026.05.09)')->required(),
            'notes' => $schema->string()->description('Optional release notes'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'name' => 'required|string|min:1|max:255',
            'version' => 'required|string|min:1|max:64',
            'notes' => 'nullable|string|max:5000',
        ]);

        $teamId = (string) (auth()->user()->current_team_id ?? '');
        $userId = (string) auth()->id();

        if ($teamId === '') {
            return $this->validationError('current_team_id missing on user');
        }

        try {
            $release = app(CreateReleaseAction::class)->execute(
                teamId: $teamId,
                userId: $userId,
                name: $validated['name'],
                version: $validated['version'],
                notes: $validated['notes'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return $this->validationError($e->getMessage());
        }

        return Response::text(json_encode([
            'id' => $release->id,
            'name' => $release->name,
            'slug' => $release->slug,
            'version' => $release->version,
            'status' => $release->status->value,
        ]));
    }
}
