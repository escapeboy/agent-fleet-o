<?php

namespace Tests\Feature\Domain\Assistant;

use App\Domain\Assistant\Services\ContextResolver;
use App\Livewire\Assistant\AssistantPanel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SelectionContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_context_resolver_renders_selection_context_for_llm(): void
    {
        $payload = json_encode(['kind' => 'experiment', 'ids' => ['id-a', 'id-b', 'id-c']]);

        $text = app(ContextResolver::class)->resolve('selection', $payload);

        $this->assertStringContainsString('3 experiment records', $text);
        $this->assertStringContainsString('id-a', $text);
        $this->assertStringContainsString('id-c', $text);
    }

    public function test_context_resolver_handles_empty_selection_gracefully(): void
    {
        $payload = json_encode(['kind' => 'experiment', 'ids' => []]);
        $text = app(ContextResolver::class)->resolve('selection', $payload);
        $this->assertStringContainsString('cleared their selection', $text);
    }

    public function test_context_resolver_falls_back_on_malformed_payload(): void
    {
        $text = app(ContextResolver::class)->resolve('selection', 'not-json');
        $this->assertStringContainsString('selected items', $text);
    }

    public function test_assistant_panel_accepts_selection_event_and_opens_chat(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(AssistantPanel::class)
            ->dispatch('assistant-set-selection', kind: 'experiment', ids: ['exp-1', 'exp-2'])
            ->assertSet('contextType', 'selection')
            ->assertDispatched('assistant-open');
    }

    public function test_empty_ids_clears_selection(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(AssistantPanel::class);
        $component->set('contextType', 'selection');
        $component->set('contextId', json_encode(['kind' => 'experiment', 'ids' => ['x']]));
        $component->dispatch('assistant-set-selection', kind: '', ids: []);
        $component->assertSet('contextType', '')->assertSet('contextId', '');
    }

    public function test_selection_ids_are_capped_at_50(): void
    {
        $ids = array_map(fn ($i) => "id-{$i}", range(1, 75));

        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(AssistantPanel::class)
            ->dispatch('assistant-set-selection', kind: 'experiment', ids: $ids);

        $contextId = $component->get('contextId');
        $decoded = json_decode($contextId, true);
        $this->assertCount(50, $decoded['ids']);
    }
}
