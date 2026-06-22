<?php

namespace App\Domain\Simulation\Actions;

use App\Domain\Simulation\Models\SimulationPersona;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;

/**
 * Produces the next user message for a persona given the conversation so far —
 * the "user-simulator" half of a simulated conversation.
 */
class SimulateUserTurnAction
{
    public function __construct(private readonly AiGatewayInterface $gateway) {}

    /**
     * @param  list<array{role: string, content: string}>  $conversation
     */
    public function execute(SimulationPersona $persona, array $conversation, string $teamId, ?string $userId): string
    {
        if ($conversation === [] && $persona->seed_message) {
            return (string) $persona->seed_message;
        }

        [$provider, $model] = $this->resolveModel();

        $response = $this->gateway->complete(new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: "You are role-playing a single end user talking to an AI agent.\n"
                ."Persona: {$persona->name}\nProfile: ".($persona->profile ?? '(none)')."\n"
                .'Your goal: '.($persona->goal ?? '(none)')."\n"
                .'Stay in character. Output ONLY your next message to the agent — no narration, no quotes.',
            userPrompt: "Conversation so far:\n".$this->render($conversation)."\n\nYour next message:",
            teamId: $teamId,
            userId: $userId,
            purpose: 'simulation.user_turn',
            temperature: 0.9,
            maxCostCredits: (int) config('simulation.caps.per_call_credit_ceiling', 2000),
        ));

        return trim($response->content);
    }

    /**
     * @param  list<array{role: string, content: string}>  $conversation
     */
    private function render(array $conversation): string
    {
        if ($conversation === []) {
            return '(no messages yet — you start)';
        }

        return implode("\n", array_map(
            fn (array $t) => ucfirst($t['role']).': '.$t['content'],
            $conversation,
        ));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveModel(): array
    {
        $full = (string) config('simulation.default_model', 'anthropic/claude-sonnet-4-5');

        if (str_contains($full, '/')) {
            [$provider, $model] = explode('/', $full, 2);

            return [$provider, $model];
        }

        return ['anthropic', $full];
    }
}
