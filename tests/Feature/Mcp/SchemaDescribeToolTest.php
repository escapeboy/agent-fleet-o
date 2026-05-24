<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Mcp\Tools\Schema\SchemaDescribeTool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class SchemaDescribeToolTest extends TestCase
{
    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }

    public function test_describes_a_known_enum_with_values(): void
    {
        $tool = new SchemaDescribeTool;
        $response = $tool->handle(new Request(['entity' => 'experiment_status']));

        $this->assertFalse($response->isError());
        $payload = $this->decode($response);

        $this->assertSame('experiment_status', $payload['entity']);
        $values = array_column($payload['values'], 'value');
        foreach (ExperimentStatus::cases() as $case) {
            $this->assertContains($case->value, $values);
        }
    }

    public function test_includes_label_when_enum_exposes_one(): void
    {
        $tool = new SchemaDescribeTool;
        $response = $tool->handle(new Request(['entity' => 'skill_type']));

        $payload = $this->decode($response);
        $this->assertArrayHasKey('label', $payload['values'][0]);
    }

    public function test_unknown_entity_returns_structured_invalid_argument(): void
    {
        $tool = new SchemaDescribeTool;
        $response = $tool->handle(new Request(['entity' => 'not_a_real_enum']));

        $this->assertTrue($response->isError());
        $payload = $this->decode($response);
        $this->assertSame('INVALID_ARGUMENT', $payload['error']['code']);
        $this->assertStringContainsString('experiment_status', $payload['error']['message']);
    }
}
