<?php

namespace Tests\Feature\Domain\Chatbot\AnswerCache;

use App\Domain\Chatbot\Models\ChatbotAnswerCacheEntry;
use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Middleware\SemanticCache;
use App\Infrastructure\AI\Services\EmbeddingService;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Livewire\Chatbots\ChatbotDetailPage;
use App\Mcp\Tools\Chatbot\ChatbotAnswerCacheConfigureTool;
use App\Mcp\Tools\Chatbot\ChatbotAnswerCachePurgeTool;
use App\Mcp\Tools\Chatbot\ChatbotAnswerCacheStatsTool;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Livewire\Livewire;
use Mockery;

class AnswerCacheControlsTest extends AnswerCacheTestCase
{
    private function actAsMemberOf(string $teamId, string $role = 'owner'): User
    {
        $user = User::factory()->create();
        Team::find($teamId)->users()->attach($user, ['role' => $role]);
        $user->update(['current_team_id' => $teamId]);
        $this->actingAs($user);
        app()->instance('mcp.team_id', $teamId);

        return $user;
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true) ?? [];
    }

    public function test_tools_respect_the_team_chatbot_feature_gate(): void
    {
        $bot = $this->chatbot();
        Team::find($bot->team_id)->update(['settings' => ['chatbot_enabled' => false]]);
        $this->actAsMemberOf($bot->team_id);

        $response = (new ChatbotAnswerCacheStatsTool)->handle(new Request(['chatbot_id' => $bot->id]));

        $this->assertTrue($response->isError());
    }

    public function test_configure_stats_and_purge_tools(): void
    {
        $bot = $this->chatbot(enabled: false);
        Team::find($bot->team_id)->update(['settings' => ['chatbot_enabled' => true]]);
        $this->actAsMemberOf($bot->team_id);

        $configured = $this->decode((new ChatbotAnswerCacheConfigureTool)->handle(new Request([
            'chatbot_id' => $bot->slug, 'enabled' => true, 'ttl_hours' => 24,
        ])));
        $this->assertTrue($configured['answer_cache']['enabled']);
        $this->assertSame(24, $configured['answer_cache']['ttl_hours']);

        $this->storeEntry($bot->refresh(), 'q', 'a', $this->axis(0));
        $stats = $this->decode((new ChatbotAnswerCacheStatsTool)->handle(new Request(['chatbot_id' => $bot->id])));
        $this->assertSame(1, $stats['entries']);

        $purged = $this->decode((new ChatbotAnswerCachePurgeTool)->handle(new Request(['chatbot_id' => $bot->id])));
        $this->assertTrue($purged['purged']);
        $this->assertSame(0, ChatbotAnswerCacheEntry::withoutGlobalScopes()->count());
    }

    public function test_tools_cannot_reach_another_teams_chatbot(): void
    {
        $foreign = $this->chatbot();
        $this->storeEntry($foreign, 'q', 'a', $this->axis(0));
        $own = $this->team();
        $own->update(['settings' => ['chatbot_enabled' => true]]);
        $this->actAsMemberOf($own->id);

        foreach ([ChatbotAnswerCacheConfigureTool::class, ChatbotAnswerCacheStatsTool::class, ChatbotAnswerCachePurgeTool::class] as $tool) {
            $response = (new $tool)->handle(new Request(['chatbot_id' => $foreign->id, 'enabled' => false]));
            $this->assertTrue($response->isError(), "{$tool} reached another team's chatbot");
        }

        $this->assertSame(1, ChatbotAnswerCacheEntry::withoutGlobalScopes()->count());
        $this->assertTrue($this->cache()->settings($foreign->refresh())['enabled']);
    }

    public function test_livewire_toggle_requires_edit_rights(): void
    {
        $bot = $this->chatbot(enabled: false);
        $team = Team::find($bot->team_id);
        $team->update(['settings' => ['chatbot_enabled' => true]]);
        app(ProviderResolver::class)->shouldReceive('availableProviders')->andReturn([]);

        // Base edition defines edit-content as always-true; deny it to exercise
        // the per-action guard (cloud gates it on TeamRole — same code path).
        Gate::define('edit-content', fn () => false);
        $this->actAsMemberOf($bot->team_id, TeamRole::Viewer->value);
        Livewire::test(ChatbotDetailPage::class, ['chatbot' => $bot])
            ->call('toggleAnswerCache')
            ->assertForbidden();
        $this->assertFalse($this->cache()->settings($bot->refresh())['enabled']);

        Gate::define('edit-content', fn () => true);
        $this->actAsMemberOf($bot->team_id, TeamRole::Admin->value);
        Livewire::test(ChatbotDetailPage::class, ['chatbot' => $bot])
            ->set('activeTab', 'configuration')
            ->call('toggleAnswerCache')
            ->assertSee('Answer Cache')
            ->assertSee('Turn off');
        $this->assertTrue($this->cache()->settings($bot->refresh())['enabled']);
    }

    public function test_gateway_semantic_cache_never_answers_judge_calls(): void
    {
        config(['semantic_cache.enabled' => true]);
        $embedding = Mockery::mock(EmbeddingService::class);
        $embedding->shouldNotReceive('embed');
        $expected = new AiResponseDTO('{"decision":"reject"}', null, new AiUsageDTO(1, 1, 0), 'openai', 'gpt-4o-mini', 1);

        $result = (new SemanticCache($embedding))->handle(
            new AiRequestDTO(provider: 'openai', model: 'gpt-4o-mini', systemPrompt: 's', userPrompt: 'u', teamId: 't', purpose: 'chatbot.answer_cache_judge'),
            fn () => $expected,
        );

        $this->assertSame($expected, $result);
    }
}
