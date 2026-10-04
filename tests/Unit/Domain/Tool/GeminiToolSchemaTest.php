<?php

namespace Tests\Unit\Domain\Tool;

use App\Domain\Tool\Services\GeminiToolSchema;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Providers\Gemini\Maps\ToolMap;
use Prism\Prism\Schema\RawSchema;
use Prism\Prism\Tool;

/**
 * Gemini rejects a list-valued `type` in function declarations (Sentry fleetq
 * #1110: "Proto field is not repeating, cannot start list"). RawSchema reaches
 * the wire untouched, so unions must be collapsed before the request is built.
 */
class GeminiToolSchemaTest extends TestCase
{
    private function tool(): Tool
    {
        return (new Tool)
            ->as('experiment_update')
            ->for('Update an experiment')
            ->withStringParameter('experiment_id', 'UUID')
            ->withParameter(new RawSchema('title', ['type' => ['string', 'null'], 'description' => 'New title']), required: false)
            ->withParameter(new RawSchema('max_steps', ['type' => ['integer', 'string']]), required: false)
            ->withParameter(new RawSchema('filters', [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => ['string', 'null']],
                    'tags' => ['type' => 'array', 'items' => ['type' => ['string', 'null']]],
                ],
            ]), required: false)
            ->using(fn (): string => 'ok');
    }

    public function test_type_unions_reach_gemini_as_single_types(): void
    {
        $mapped = ToolMap::map(GeminiToolSchema::apply([$this->tool()]))[0]['parameters'];

        $this->assertSame(['type' => 'string', 'description' => 'New title', 'nullable' => true], $mapped['properties']['title']);
        $this->assertSame('integer', $mapped['properties']['max_steps']['type']);
        $this->assertArrayNotHasKey('nullable', $mapped['properties']['max_steps']);

        $filters = $mapped['properties']['filters'];
        $this->assertSame(['type' => 'string', 'nullable' => true], $filters['properties']['status']);
        $this->assertSame(['type' => 'string', 'nullable' => true], $filters['properties']['tags']['items']);

        array_walk_recursive($mapped, function ($value, $key): void {
            if ($key === 'type') {
                $this->assertIsString($value);
            }
        });
    }

    public function test_required_parameters_and_the_input_tool_are_untouched(): void
    {
        $tool = $this->tool();

        $applied = GeminiToolSchema::apply([$tool])[0];

        $this->assertNotSame($tool, $applied);
        $this->assertSame(['experiment_id'], $applied->requiredParameters());
        $this->assertSame(['string', 'null'], $tool->parameters()['title']->toArray()['type']);
    }

    public function test_tools_without_unions_are_returned_as_is(): void
    {
        $tool = (new Tool)->as('ping')->for('Ping')->withStringParameter('host', 'Host')->using(fn (): string => 'ok');

        $this->assertSame($tool, GeminiToolSchema::apply([$tool])[0]);
    }
}
