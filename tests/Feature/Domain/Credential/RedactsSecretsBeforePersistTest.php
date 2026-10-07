<?php

namespace Tests\Feature\Domain\Credential;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Agent\Models\AiRun;
use App\Domain\AgentSession\Models\AgentSessionEvent;
use App\Domain\Assistant\Models\AssistantMessage;
use App\Domain\Chatbot\Models\ChatbotMessage;
use App\Domain\Credential\Concerns\RedactsSecretsBeforePersist;
use App\Domain\Credential\Services\SecretRedactor;
use App\Domain\Crew\Models\CrewTaskExecution;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Memory\Models\Memory;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Models\SkillExecution;
use App\Infrastructure\AI\Models\LlmRequestLog;
use App\Models\Artifact;
use App\Models\ArtifactVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class RedactsSecretsBeforePersistTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create();
        $this->agent = Agent::factory()->for($this->team)->create();
    }

    private function execution(array $output): AgentExecution
    {
        return AgentExecution::create([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'status' => 'success',
            'duration_ms' => 10,
            'cost_credits' => 1,
            'tool_calls_count' => 0,
            'llm_steps_count' => 1,
            'output' => $output,
        ]);
    }

    private function memory(string $content): Memory
    {
        return Memory::create([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'content' => $content,
            'source_type' => 'test',
        ]);
    }

    public function test_flag_off_stores_verbatim(): void
    {
        config(['ai_safety.redact_before_persist' => false]);

        $execution = $this->execution(['text' => 'DB_PASSWORD=s3cr3tPass!']);

        $this->assertSame('DB_PASSWORD=s3cr3tPass!', $execution->fresh()->output['text']);
    }

    public function test_flag_on_redacts_array_column(): void
    {
        config(['ai_safety.redact_before_persist' => true]);

        $execution = $this->execution(['text' => "ok\nDB_PASSWORD=s3cr3tPass!", 'n' => 3]);

        $this->assertSame(['text' => "ok\nDB_PASSWORD=[REDACTED]", 'n' => 3], $execution->fresh()->output);
    }

    public function test_flag_on_redacts_text_columns(): void
    {
        config(['ai_safety.redact_before_persist' => true]);

        $memory = $this->memory('prod url postgres://app:hunter2hunter@db/x');

        $this->assertSame('prod url postgres://app:[REDACTED]@db/x', $memory->fresh()->content);

        $artifact = Artifact::create(['team_id' => $this->team->id, 'name' => 'a', 'type' => 'text']);
        $version = ArtifactVersion::create([
            'team_id' => $this->team->id,
            'artifact_id' => $artifact->id,
            'version' => 1,
            'content' => 'APP_KEY=base64:'.str_repeat('aB1c', 10).'xyz=',
        ]);

        $this->assertSame('APP_KEY=[REDACTED]', $version->fresh()->content);
    }

    public function test_only_dirty_attributes_are_redacted(): void
    {
        config(['ai_safety.redact_before_persist' => false]);
        $memory = $this->memory('DB_PASSWORD=s3cr3tPass!');

        config(['ai_safety.redact_before_persist' => true]);
        $memory->update(['importance' => 0.9]);

        $this->assertSame('DB_PASSWORD=s3cr3tPass!', $memory->fresh()->content);

        $memory->update(['content' => 'DB_PASSWORD=an0therPass!']);

        $this->assertSame('DB_PASSWORD=[REDACTED]', $memory->fresh()->content);
    }

    public function test_redactor_failure_fails_closed_and_is_reported(): void
    {
        Exceptions::fake();
        config(['ai_safety.redact_before_persist' => true]);
        $this->app->bind(SecretRedactor::class, fn () => throw new RuntimeException('boom'));

        $execution = $this->execution(['text' => 'DB_PASSWORD=s3cr3tPass!']);
        $memory = $this->memory('DB_PASSWORD=s3cr3tPass!');

        $this->assertSame(['_redaction_failed' => true], $execution->fresh()->output);
        $this->assertSame('[REDACTION_FAILED]', $memory->fresh()->content);
        Exceptions::assertReported(RuntimeException::class);
    }

    /**
     * @return array<string, array{class-string, list<string>}>
     */
    public static function models(): array
    {
        return [
            'LlmRequestLog' => [LlmRequestLog::class, ['response_body']],
            'AiRun' => [AiRun::class, ['raw_output', 'parsed_output', 'prompt_snapshot', 'reasoning_chain']],
            'AgentExecution' => [AgentExecution::class, ['input', 'output', 'tools_used']],
            'AssistantMessage' => [AssistantMessage::class, ['content', 'tool_calls', 'tool_results']],
            'CrewTaskExecution' => [CrewTaskExecution::class, ['output']],
            'ExperimentStage' => [ExperimentStage::class, ['output_snapshot']],
            'PlaybookStep' => [PlaybookStep::class, ['output']],
            'ArtifactVersion' => [ArtifactVersion::class, ['content']],
            'Memory' => [Memory::class, ['content']],
            'AgentSessionEvent' => [AgentSessionEvent::class, ['payload']],
            'ChatbotMessage' => [ChatbotMessage::class, ['content']],
            'SkillExecution' => [SkillExecution::class, ['output']],
        ];
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $attributes
     */
    #[DataProvider('models')]
    public function test_model_uses_trait_and_declares_real_columns(string $class, array $attributes): void
    {
        $this->assertContains(RedactsSecretsBeforePersist::class, class_uses_recursive($class));

        $model = new $class;
        $method = new ReflectionMethod($model, 'redactableAttributes');

        $this->assertEqualsCanonicalizing($attributes, $method->invoke($model));

        foreach ($attributes as $attribute) {
            $this->assertTrue(
                \Schema::hasColumn($model->getTable(), $attribute),
                "{$class}.{$attribute} is not a column",
            );
        }
    }
}
