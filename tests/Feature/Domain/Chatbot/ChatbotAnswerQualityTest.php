<?php

namespace Tests\Feature\Domain\Chatbot;

use App\Domain\Agent\Models\Agent;
use App\Domain\Chatbot\Enums\ChatbotType;
use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotKnowledgeSource;
use App\Domain\Chatbot\Services\ChatbotResponseService;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class ChatbotAnswerQualityTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ChatbotResponseService
    {
        // Build without the constructor: the methods under test use none of the
        // injected deps, and one of them (Barsy EmbeddingServiceInterface) isn't
        // loadable in the base-standalone test context.
        return (new ReflectionClass(ChatbotResponseService::class))->newInstanceWithoutConstructor();
    }

    private function invokePrivate(object $obj, string $method, mixed ...$args): mixed
    {
        $m = new ReflectionMethod($obj, $method);
        $m->setAccessible(true);

        return $m->invoke($obj, ...$args);
    }

    // --- §1: strip tool-call narration -------------------------------------

    public function test_strips_leading_tool_call_narration(): void
    {
        $reply = "I need to find the supported formats — calling barsy_knowledge_search because I should.\n\nThe formats are PNG and MP4.";

        $this->assertSame(
            'The formats are PNG and MP4.',
            $this->invokePrivate($this->service(), 'stripToolCallNarration', $reply),
        );
    }

    public function test_does_not_strip_a_legitimate_answer(): void
    {
        // No tool-call marker → narrowed regex must leave it untouched.
        $reply = "I'll help you reset your password. Click the link in Settings.";

        $this->assertSame($reply, $this->invokePrivate($this->service(), 'stripToolCallNarration', $reply));
    }

    public function test_does_not_strip_permission_text_without_tool_marker(): void
    {
        // Handled by the system prompt, NOT the regex (per the narrowed strategy).
        $reply = "You're right - I need your permission. Anyway, the answer is 42.";

        $this->assertSame($reply, $this->invokePrivate($this->service(), 'stripToolCallNarration', $reply));
    }

    // --- §2: language + no-narration in system prompt ----------------------

    public function test_system_prompt_includes_language_and_no_narration_rules(): void
    {
        $agent = new Agent(['name' => 'HelpBot', 'role' => 'helper', 'goal' => 'assist']);
        $chatbot = new Chatbot(['type' => ChatbotType::HelpBot]);
        $chatbot->setRelation('agent', $agent);

        $prompt = $this->invokePrivate($this->service(), 'buildChatbotSystemPrompt', $chatbot);

        $this->assertStringContainsString('same language', $prompt);
        $this->assertStringContainsString('Never narrate', $prompt);
    }

    // --- §3: citations enriched for non-support chatbot types --------------

    public function test_rag_sources_enriched_for_help_bot(): void
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'T '.bin2hex(random_bytes(3)),
            'slug' => 't-'.bin2hex(random_bytes(3)),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $agent = Agent::factory()->create(['team_id' => $team->id]);
        $chatbot = Chatbot::create([
            'team_id' => $team->id,
            'agent_id' => $agent->id,
            'name' => 'Help',
            'slug' => 'help-'.bin2hex(random_bytes(3)),
            'type' => ChatbotType::HelpBot,
        ]);
        $source = ChatbotKnowledgeSource::create([
            'chatbot_id' => $chatbot->id,
            'team_id' => $team->id,
            'type' => 'url',
            'name' => 'Getting Started Guide',
            'source_url' => 'https://docs.example.com/start',
        ]);

        $chunks = [['id' => 'chunk-1', 'source_id' => $source->id, 'similarity' => 0.71]];

        $meta = $this->invokePrivate($this->service(), 'buildRagSourceMeta', $chatbot, $chunks);

        $this->assertSame('Getting Started Guide', $meta[0]['source_name']);
        $this->assertSame('https://docs.example.com/start', $meta[0]['source_url']);
    }
}
