<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Schema;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Credential\Enums\CredentialType;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Outbound\Enums\OutboundChannel;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Schema-context-before-action (dotCMS-borrowed): exposes the valid values of
 * core platform enums so agents can discover allowed states/types instead of
 * guessing or hallucinating field values before a create/update call.
 *
 * Pure schema — touches no tenant data, so it is tenant-safe by construction.
 */
#[IsReadOnly]
#[IsIdempotent]
class SchemaDescribeTool extends Tool
{
    use HasStructuredErrors;

    /**
     * entity => backing enum class. Source of truth — never drifts from code.
     *
     * @var array<string, class-string>
     */
    private const ENTITIES = [
        'experiment_status' => ExperimentStatus::class,
        'skill_type' => SkillType::class,
        'workflow_node_type' => WorkflowNodeType::class,
        'agent_status' => AgentStatus::class,
        'credential_type' => CredentialType::class,
        'outbound_channel' => OutboundChannel::class,
        'approval_status' => ApprovalStatus::class,
    ];

    protected string $name = 'schema_describe';

    protected string $description = 'Describe the valid values of a core platform enum (e.g. experiment_status, skill_type, workflow_node_type) so you can choose allowed states/types before creating or updating entities.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'entity' => $schema->string()
                ->description('Enum to describe. One of: '.implode(', ', array_keys(self::ENTITIES)).'.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['entity' => 'required|string']);
        $entity = $validated['entity'];

        $enumClass = self::ENTITIES[$entity] ?? null;
        if ($enumClass === null) {
            return $this->invalidArgumentError(
                "Unknown entity '{$entity}'. Valid entities: ".implode(', ', array_keys(self::ENTITIES)).'.',
            );
        }

        $values = array_map(static function (\BackedEnum $case): array {
            $entry = ['value' => $case->value];
            if (method_exists($case, 'label')) {
                $entry['label'] = $case->label();
            }

            return $entry;
        }, $enumClass::cases());

        return Response::text(json_encode([
            'entity' => $entity,
            'values' => $values,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
