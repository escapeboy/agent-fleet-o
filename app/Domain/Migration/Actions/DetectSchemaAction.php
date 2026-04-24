<?php

namespace App\Domain\Migration\Actions;

use App\Domain\Migration\DTOs\SchemaProposal;
use App\Domain\Migration\Enums\MigrationEntityType;
use App\Domain\Migration\Enums\MigrationSource;
use App\Domain\Migration\Enums\MigrationStatus;
use App\Domain\Migration\Models\MigrationRun;
use App\Domain\Migration\Services\CsvParser;
use App\Domain\Migration\Services\Importers\ImporterRegistry;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Models\GlobalSetting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

final class DetectSchemaAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly CsvParser $csvParser,
        private readonly ImporterRegistry $registry,
    ) {}

    public function execute(
        User $user,
        string $payload,
        MigrationSource $source,
        MigrationEntityType $entityType,
    ): MigrationRun {
        $teamId = $user->current_team_id;
        if (! $teamId) {
            throw new \RuntimeException('User has no active team');
        }

        $byteSize = strlen($payload);

        $run = MigrationRun::create([
            'team_id' => $teamId,
            'user_id' => $user->id,
            'entity_type' => $entityType->value,
            'source' => $source->value,
            'source_bytes' => $byteSize,
            'source_payload' => $payload,
            'status' => MigrationStatus::Analysing->value,
            'stats' => [],
            'errors' => [],
        ]);

        try {
            $sample = $this->buildSample($source, $payload);
            $proposal = $this->askLlm($user, $entityType, $sample);

            $run->update([
                'proposed_mapping' => $proposal->columnMap,
                'status' => MigrationStatus::AwaitingConfirmation->value,
                'stats' => [
                    'confidence' => $proposal->confidence,
                    'warnings' => $proposal->warnings,
                    'headers' => $sample['headers'] ?? [],
                    'sample_row_count' => count($sample['rows'] ?? []),
                ],
            ]);

            return $run->refresh();
        } catch (\Throwable $e) {
            Log::error('DetectSchemaAction failed', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
            $run->update([
                'status' => MigrationStatus::Failed->value,
                'errors' => [['row' => null, 'message' => 'schema detection failed: '.$e->getMessage()]],
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * @return array{headers: list<string>, rows: list<array<string, string>>, row_count: int}
     */
    private function buildSample(MigrationSource $source, string $payload): array
    {
        if ($source === MigrationSource::Csv) {
            return $this->csvParser->parse($payload, maxRows: 10);
        }

        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new \RuntimeException('JSON payload must decode to an array of objects');
        }
        $rows = array_is_list($decoded) ? $decoded : [$decoded];
        $sample = array_slice($rows, 0, 10);
        $headers = [];
        $normalised = [];
        foreach ($sample as $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach ($row as $key => $_) {
                $headers[(string) $key] = true;
            }
        }
        foreach ($sample as $row) {
            if (! is_array($row)) {
                continue;
            }
            $flat = [];
            foreach (array_keys($headers) as $header) {
                $value = $row[$header] ?? '';
                $flat[(string) $header] = is_scalar($value) ? (string) $value : json_encode($value);
            }
            $normalised[] = $flat;
        }

        return ['headers' => array_keys($headers), 'rows' => $normalised, 'row_count' => count($rows)];
    }

    /**
     * @param  array{headers: list<string>, rows: list<array<string, string>>, row_count: int}  $sample
     */
    private function askLlm(User $user, MigrationEntityType $entityType, array $sample): SchemaProposal
    {
        $importer = $this->registry->resolve($entityType);
        $supportedLines = [];
        foreach ($importer->supportedAttributes() as $attr => $desc) {
            $supportedLines[] = "- `{$attr}` — {$desc}";
        }

        $headers = $sample['headers'];
        $rowLines = [];
        foreach ($sample['rows'] as $row) {
            $rowLines[] = json_encode($row, JSON_UNESCAPED_UNICODE);
        }

        $system = "You are a data-migration schema detector. Given a CSV/JSON sample from the user's previous tool (e.g. Salesforce, HubSpot, Intercom), map each source column to the closest target attribute on the FleetQ `{$entityType->value}` entity.";

        $userPrompt = sprintf(
            "Target attributes for `%s`:\n%s\n\nSource headers: %s\n\nFirst rows (JSON):\n%s\n\nReturn a JSON object with:\n- `column_map`: an object whose keys are EXACTLY the source header strings and values are one of the target attribute names (or null/empty string if no clean mapping).\n- `confidence`: float 0..1 for overall mapping quality.\n- `warnings`: short list of issues (ambiguous columns, malformed rows).\n\nRules:\n- Never invent target attributes — only use names from the list above.\n- Unmapped columns will be preserved in the `metadata` attribute, so you can set `metadata` as the target for anything that looks like notes, tags, extra identifiers.\n- Strongly prefer `email` for columns whose values look like addresses.",
            $entityType->value,
            implode("\n", $supportedLines),
            implode(', ', $headers),
            implode("\n", $rowLines) ?: '(no sample rows)'
        );

        $schema = new ObjectSchema(
            name: 'schema_proposal',
            description: 'Column mapping proposal',
            properties: [
                new ObjectSchema(
                    name: 'column_map',
                    description: 'Source header → target attribute',
                    properties: array_map(
                        fn (string $header) => new StringSchema(
                            name: $header,
                            description: 'Target attribute name or empty string',
                            nullable: true,
                        ),
                        $headers,
                    ),
                    requiredFields: [],
                ),
                new StringSchema(name: 'confidence', description: '0..1 confidence', nullable: false),
                new ArraySchema(
                    name: 'warnings',
                    description: 'Short issue notes',
                    items: new StringSchema(name: 'warning', description: ''),
                ),
            ],
            requiredFields: ['column_map', 'confidence', 'warnings'],
        );

        $teamSettings = $user->currentTeam?->settings ?? [];
        $provider = ($teamSettings['assistant_llm_provider'] ?? null)
            ?? GlobalSetting::get('assistant_llm_provider')
            ?? GlobalSetting::get('default_llm_provider', 'anthropic');
        $model = ($teamSettings['assistant_llm_model'] ?? null)
            ?? GlobalSetting::get('assistant_llm_model')
            ?? GlobalSetting::get('default_llm_model', 'claude-haiku-4-5-20251001');

        $request = new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: $system,
            userPrompt: $userPrompt,
            maxTokens: 2048,
            outputSchema: $schema,
            userId: $user->id,
            teamId: $user->current_team_id,
            purpose: 'migration_schema_detection',
            temperature: 0.1,
        );

        $response = $this->gateway->complete($request);
        $parsed = $response->parsedOutput ?? (is_string($response->content) ? json_decode($response->content, true) : null);
        if (! is_array($parsed)) {
            throw new \RuntimeException('Schema detector returned non-JSON response');
        }

        $columnMap = is_array($parsed['column_map'] ?? null) ? $parsed['column_map'] : [];
        $confidence = (float) ($parsed['confidence'] ?? 0.0);
        $warnings = is_array($parsed['warnings'] ?? null) ? array_values(array_filter(array_map('strval', $parsed['warnings']))) : [];

        $cleanedMap = [];
        $supported = $importer->supportedAttributes();
        foreach ($headers as $header) {
            $target = $columnMap[$header] ?? null;
            if (is_string($target) && $target !== '' && isset($supported[$target])) {
                $cleanedMap[$header] = $target;
            } else {
                $cleanedMap[$header] = null;
            }
        }

        return new SchemaProposal(
            entityType: $entityType->value,
            columnMap: $cleanedMap,
            confidence: max(0.0, min(1.0, $confidence)),
            warnings: $warnings,
        );
    }
}
