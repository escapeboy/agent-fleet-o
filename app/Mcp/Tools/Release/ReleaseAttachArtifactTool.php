<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Release;

use App\Domain\Release\Actions\AttachArtifactAction;
use App\Domain\Release\Models\Release;
use App\Mcp\Concerns\HasStructuredErrors;
use App\Models\Artifact;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsDestructive]
#[IsIdempotent]
class ReleaseAttachArtifactTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'release_attach_artifact';

    protected string $description = 'Attach an artifact to a release. Idempotent on (release, artifact); re-attach updates the snapshot version.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'release_id' => $schema->string()->description('Release UUID')->required(),
            'artifact_id' => $schema->string()->description('Artifact UUID')->required(),
            'sort_order' => $schema->integer()->description('Optional sort order'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'release_id' => 'required|string',
            'artifact_id' => 'required|string',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $release = Release::find($validated['release_id']);
        if (! $release) {
            return $this->notFoundError('release');
        }

        $artifact = Artifact::find($validated['artifact_id']);
        if (! $artifact) {
            return $this->notFoundError('artifact');
        }

        try {
            $pivot = app(AttachArtifactAction::class)->execute(
                $release,
                $artifact,
                $validated['sort_order'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return $this->validationError($e->getMessage());
        }

        return Response::text(json_encode([
            'release_id' => $pivot->release_id,
            'artifact_id' => $pivot->artifact_id,
            'artifact_version' => $pivot->artifact_version,
            'sort_order' => $pivot->sort_order,
        ]));
    }
}
