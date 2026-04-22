<?php

namespace App\Mcp\Tools\Project;

use App\Domain\Project\Services\NaturalLanguageScheduleParser;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ProjectScheduleNlpTool extends Tool
{
    protected string $name = 'project_schedule_nlp';

    protected string $description = 'Parse a natural language schedule description into a structured schedule config. Use the returned config with project_create (schedule field) or project_schedule_manage (operation=update). Example inputs: "every Monday at 9am London time", "daily at midnight UTC", "every 30 minutes", "twice a day".';

    public function schema(JsonSchema $schema): array
    {
        return [
            'description' => $schema->string()
                ->description('Natural language schedule description, e.g. "every Monday at 9am London time"')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'description' => 'required|string|max:500',
        ]);

        try {
            $result = app(NaturalLanguageScheduleParser::class)->parse($validated['description']);

            return Response::text(json_encode([
                'human_readable' => $result->humanReadable,
                'schedule' => [
                    'frequency' => $result->frequency->value,
                    'cron_expression' => $result->cronExpression,
                    'timezone' => $result->timezone,
                    'overlap_policy' => $result->overlapPolicy->value,
                ],
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
