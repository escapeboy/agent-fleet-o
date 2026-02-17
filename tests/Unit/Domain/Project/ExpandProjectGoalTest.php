<?php

namespace Tests\Unit\Domain\Project;

use App\Domain\Project\Actions\ExpandProjectGoalAction;
use PHPUnit\Framework\TestCase;

class ExpandProjectGoalTest extends TestCase
{
    public function test_validate_no_cycles_passes_for_valid_dag(): void
    {
        $action = new ExpandProjectGoalAction(
            $this->createMock(\App\Infrastructure\AI\Contracts\AiGatewayInterface::class),
            $this->createMock(\App\Infrastructure\AI\Services\ProviderResolver::class),
        );

        // Use reflection to test the private method
        $method = new \ReflectionMethod($action, 'validateNoCycles');

        $features = [
            ['title' => 'Auth', 'dependencies' => []],
            ['title' => 'API', 'dependencies' => [0]],
            ['title' => 'Frontend', 'dependencies' => [0, 1]],
        ];

        // Should not throw
        $method->invoke($action, $features);
        $this->assertTrue(true);
    }

    public function test_validate_no_cycles_detects_cycle(): void
    {
        $action = new ExpandProjectGoalAction(
            $this->createMock(\App\Infrastructure\AI\Contracts\AiGatewayInterface::class),
            $this->createMock(\App\Infrastructure\AI\Services\ProviderResolver::class),
        );

        $method = new \ReflectionMethod($action, 'validateNoCycles');

        $features = [
            ['title' => 'A', 'dependencies' => [2]],
            ['title' => 'B', 'dependencies' => [0]],
            ['title' => 'C', 'dependencies' => [1]],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/cycle/i');
        $method->invoke($action, $features);
    }

    public function test_parse_features_from_json(): void
    {
        $action = new ExpandProjectGoalAction(
            $this->createMock(\App\Infrastructure\AI\Contracts\AiGatewayInterface::class),
            $this->createMock(\App\Infrastructure\AI\Services\ProviderResolver::class),
        );

        $method = new \ReflectionMethod($action, 'parseFeatures');

        $json = json_encode(['features' => [
            ['title' => 'Auth', 'description' => 'User authentication'],
            ['title' => 'API', 'description' => 'REST API'],
        ]]);

        $result = $method->invoke($action, $json);

        $this->assertCount(2, $result);
        $this->assertEquals('Auth', $result[0]['title']);
    }

    public function test_parse_features_strips_code_fences(): void
    {
        $action = new ExpandProjectGoalAction(
            $this->createMock(\App\Infrastructure\AI\Contracts\AiGatewayInterface::class),
            $this->createMock(\App\Infrastructure\AI\Services\ProviderResolver::class),
        );

        $method = new \ReflectionMethod($action, 'parseFeatures');

        $content = "```json\n[{\"title\": \"Auth\"}]\n```";

        $result = $method->invoke($action, $content);

        $this->assertCount(1, $result);
        $this->assertEquals('Auth', $result[0]['title']);
    }

    public function test_parse_features_throws_on_invalid_json(): void
    {
        $action = new ExpandProjectGoalAction(
            $this->createMock(\App\Infrastructure\AI\Contracts\AiGatewayInterface::class),
            $this->createMock(\App\Infrastructure\AI\Services\ProviderResolver::class),
        );

        $method = new \ReflectionMethod($action, 'parseFeatures');

        $this->expectException(\RuntimeException::class);
        $method->invoke($action, 'not valid json');
    }

    public function test_validate_no_cycles_handles_empty_features(): void
    {
        $action = new ExpandProjectGoalAction(
            $this->createMock(\App\Infrastructure\AI\Contracts\AiGatewayInterface::class),
            $this->createMock(\App\Infrastructure\AI\Services\ProviderResolver::class),
        );

        $method = new \ReflectionMethod($action, 'validateNoCycles');

        // Should not throw
        $method->invoke($action, []);
        $this->assertTrue(true);
    }
}
