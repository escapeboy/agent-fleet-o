<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Agent\Actions\CreateAgentAction;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CreateAgentTool implements Tool
{
    public function name(): string
    {
        return 'create_agent';
    }

    public function description(): string
    {
        return 'Create a new AI agent. Provider/model default to the team\'s configured default — only pass them when the user explicitly asks for a specific provider AND that provider has credentials configured.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('Agent name'),
            'role' => $schema->string()->description('Agent role description'),
            'goal' => $schema->string()->description('Agent goal'),
            'backstory' => $schema->string()->description('Agent backstory'),
            'provider' => $schema->string()->description('LLM provider key (e.g. anthropic, openai, google). Defaults to the team\'s configured provider. If passed but the team has no credentials for it, the team default is used instead.'),
            'model' => $schema->string()->description('LLM model name. Defaults to the team\'s configured model.'),
        ];
    }

    public function handle(Request $request): string
    {
        try {
            $teamId = auth()->user()->current_team_id;
            $team = Team::find($teamId);
            $resolver = app(ProviderResolver::class);
            $resolved = $resolver->resolve(team: $team);

            $providerInput = $request->get('provider');
            $modelInput = $request->get('model');

            $useProvider = $resolved['provider'];
            $useModel = $resolved['model'];
            if ($providerInput !== null) {
                $available = $resolver->availableProviders($team);
                if (array_key_exists($providerInput, $available)) {
                    $useProvider = $providerInput;
                    $useModel = $modelInput ?? $resolved['model'];
                }
            } elseif ($modelInput !== null) {
                $useModel = $modelInput;
            }

            $agent = app(CreateAgentAction::class)->execute(
                name: $request->get('name'),
                provider: $useProvider,
                model: $useModel,
                role: $request->get('role'),
                goal: $request->get('goal'),
                backstory: $request->get('backstory'),
                teamId: $teamId,
            );

            return json_encode([
                'success' => true,
                'agent_id' => $agent->id,
                'name' => $agent->name,
                'status' => $agent->status->value,
                'url' => route('agents.show', $agent),
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
