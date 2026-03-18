<?php

namespace App\Infrastructure\AI;

use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Generator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\UniqueIdGenerator;

/**
 * Bridge adapter: exposes FleetQ's AiGatewayInterface as a NeuronAI AIProviderInterface.
 *
 * All LLM calls made by Neuron agents/RAG go through the PrismAiGateway middleware pipeline
 * (budget enforcement, semantic cache, rate limiting, usage tracking).
 */
class NeuronPrismProvider implements AIProviderInterface
{
    private ?string $storedSystemPrompt = null;

    /** @var ToolInterface[] */
    private array $storedTools = [];

    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly string $provider,
        private readonly string $model,
        private readonly ?string $teamId = null,
        private readonly ?string $agentId = null,
        private readonly string $purpose = 'neuron.chat',
        private readonly float $temperature = 0.7,
        private readonly int $maxTokens = 4096,
    ) {}

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->storedSystemPrompt = $prompt;

        return $this;
    }

    /** @param ToolInterface[] $tools */
    public function setTools(array $tools): AIProviderInterface
    {
        $this->storedTools = $tools;

        return $this;
    }

    public function messageMapper(): MessageMapperInterface
    {
        return new class implements MessageMapperInterface
        {
            public function map(array $messages): array
            {
                return $messages;
            }
        };
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        return new class implements ToolMapperInterface
        {
            public function map(array $tools): array
            {
                return $tools;
            }
        };
    }

    public function chat(Message ...$messages): Message
    {
        [$systemExtra, $userPrompt] = $this->buildPromptParts($messages);

        $systemPrompt = trim(implode("\n\n", array_filter([
            $this->storedSystemPrompt,
            ...$systemExtra,
        ])));

        $response = $this->gateway->complete(new AiRequestDTO(
            provider: $this->provider,
            model: $this->model,
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            maxTokens: $this->maxTokens,
            teamId: $this->teamId,
            agentId: $this->agentId,
            purpose: $this->purpose,
            temperature: $this->temperature,
        ));

        $message = AssistantMessage::make($response->content);
        $message->setUsage(new Usage($response->usage->promptTokens, $response->usage->completionTokens));

        return $message;
    }

    /**
     * Yield the response as a single text chunk (Prism doesn't support true streaming
     * through the bridge — the gateway complete() call returns the full response).
     *
     * @return Generator<int, TextChunk, mixed, Message>
     */
    public function stream(Message ...$messages): Generator
    {
        [$systemExtra, $userPrompt] = $this->buildPromptParts($messages);

        $systemPrompt = trim(implode("\n\n", array_filter([
            $this->storedSystemPrompt,
            ...$systemExtra,
        ])));

        $messageId = UniqueIdGenerator::generateId();

        $response = $this->gateway->complete(new AiRequestDTO(
            provider: $this->provider,
            model: $this->model,
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            maxTokens: $this->maxTokens,
            teamId: $this->teamId,
            agentId: $this->agentId,
            purpose: $this->purpose.'.stream',
            temperature: $this->temperature,
        ));

        // Emit content word-by-word to simulate streaming output through Neuron's pipeline
        $content = $response->content;
        $words = mb_str_split($content, 50); // 50-char chunks
        foreach ($words as $chunk) {
            yield new TextChunk($messageId, $chunk);
        }

        $message = AssistantMessage::make($content);
        $message->setUsage(new Usage($response->usage->promptTokens, $response->usage->completionTokens));

        return $message;
    }

    /**
     * Request a structured (JSON) response conforming to $response_schema.
     * Appends a JSON schema instruction to the system prompt and returns the raw
     * assistant message — Neuron will deserialize the JSON into $class.
     *
     * @param  Message|Message[]  $messages
     */
    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        $msgs = is_array($messages) ? $messages : [$messages];
        [$systemExtra, $userPrompt] = $this->buildPromptParts($msgs);

        $schemaInstruction = "Respond ONLY with a valid JSON object matching this schema (no markdown, no prose):\n"
            .json_encode($response_schema, JSON_PRETTY_PRINT);

        $systemPrompt = trim(implode("\n\n", array_filter([
            $this->storedSystemPrompt,
            ...$systemExtra,
            $schemaInstruction,
        ])));

        $response = $this->gateway->complete(new AiRequestDTO(
            provider: $this->provider,
            model: $this->model,
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            maxTokens: $this->maxTokens,
            teamId: $this->teamId,
            agentId: $this->agentId,
            purpose: $this->purpose.'.structured',
            temperature: 0.1, // Low temperature for deterministic JSON
        ));

        $message = AssistantMessage::make($response->content);
        $message->setUsage(new Usage($response->usage->promptTokens, $response->usage->completionTokens));

        return $message;
    }

    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        // Bridge delegates to PrismAiGateway which manages its own HTTP client
        return $this;
    }

    /**
     * Convert a flat array of Neuron Message objects into system/user prompt strings.
     * Single message → direct userPrompt.
     * Multi-turn → history prepended to the final user message.
     *
     * @param  Message[]  $messages
     * @return array{0: string[], 1: string}
     */
    private function buildPromptParts(array $messages): array
    {
        if (empty($messages)) {
            return [[], ''];
        }

        if (count($messages) === 1) {
            return [[], $messages[0]->getContent() ?? ''];
        }

        // Multi-turn: format all but the last message as conversation history
        $history = [];
        foreach (array_slice($messages, 0, -1) as $message) {
            $role = strtoupper($message->getRole());
            $content = $message->getContent() ?? '';
            if ($content !== '') {
                $history[] = "[{$role}]: {$content}";
            }
        }

        $lastContent = end($messages)->getContent() ?? '';
        $userPrompt = empty($history)
            ? $lastContent
            : implode("\n\n", $history)."\n\n[USER]: {$lastContent}";

        return [[], $userPrompt];
    }
}
