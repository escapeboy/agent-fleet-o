<?php

namespace Tests\Feature\Domain\Knowledge;

use App\Domain\Knowledge\Services\KnowledgeBaseRAGFactory;
use App\Domain\Knowledge\Services\PgVectorKnowledgeStore;
use App\Domain\Knowledge\Services\PrismEmbeddingsProvider;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\Contracts\EmbeddingProviderInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\NeuronPrismProvider;
use Mockery;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\RAG;
use Tests\TestCase;

/**
 * neuron-ai 4 contract for FleetQ's bridges: providers return ProviderResponse,
 * system prompts may be SystemMessage objects, Document is accessor-only.
 */
class NeuronBridgeTest extends TestCase
{
    /** @var list<AiRequestDTO> */
    private array $requests = [];

    private function provider(): NeuronPrismProvider
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturnUsing(function (AiRequestDTO $request): AiResponseDTO {
            $this->requests[] = $request;

            return new AiResponseDTO('{"answer":42}', null, new AiUsageDTO(11, 7, 1), $request->provider, $request->model, 5);
        });

        return new NeuronPrismProvider($gateway, 'anthropic', 'claude-sonnet-4-6', teamId: 'team-1');
    }

    public function test_chat_returns_provider_response_with_message_and_usage(): void
    {
        $provider = $this->provider()->systemPrompt(new SystemMessage('Be brief.'));

        $response = $provider->chat(new UserMessage('Hello'));

        $this->assertInstanceOf(ProviderResponse::class, $response);
        $this->assertSame('{"answer":42}', $response->message()->getContent());
        $this->assertSame(11, $response->message()->getUsage()->inputTokens);
        $this->assertSame('Be brief.', $this->requests[0]->systemPrompt);
        $this->assertSame('Hello', $this->requests[0]->userPrompt);
        $this->assertSame('claude-sonnet-4-6', $provider->getModel());
    }

    public function test_structured_and_stream_return_provider_response(): void
    {
        $provider = $this->provider();

        $structured = $provider->structured(new UserMessage('Q'), \stdClass::class, ['type' => 'object']);
        $this->assertInstanceOf(ProviderResponse::class, $structured);
        $this->assertStringContainsString('valid JSON object', $this->requests[0]->systemPrompt);

        $stream = $provider->stream(new UserMessage('Q'));
        $text = '';
        foreach ($stream as $chunk) {
            $text .= $chunk->content;
        }
        $this->assertSame('{"answer":42}', $text);
        $this->assertInstanceOf(ProviderResponse::class, $stream->getReturn());
    }

    public function test_embeddings_provider_sets_the_document_embedding(): void
    {
        $embedder = Mockery::mock(EmbeddingProviderInterface::class);
        $embedder->shouldReceive('embed')->with('some text')->andReturn([0.1, 0.2, 0.3]);
        $this->app->instance(EmbeddingProviderInterface::class, $embedder);

        $document = (new PrismEmbeddingsProvider)->embedDocument(new Document('some text'));

        $this->assertSame([0.1, 0.2, 0.3], $document->getEmbedding());
    }

    public function test_rag_factory_builds_a_neuron_4_rag(): void
    {
        $this->app->instance(AiGatewayInterface::class, Mockery::mock(AiGatewayInterface::class));

        $rag = app(KnowledgeBaseRAGFactory::class)->make('kb-1', 'anthropic', 'claude-sonnet-4-6', 'team-1');

        $this->assertInstanceOf(RAG::class, $rag);
        $resolve = fn (string $method) => (new \ReflectionMethod($rag, $method))->invoke($rag);
        $this->assertInstanceOf(NeuronPrismProvider::class, $resolve('provider'));
        $this->assertInstanceOf(PgVectorKnowledgeStore::class, $resolve('vectorStore'));
        $this->assertInstanceOf(PrismEmbeddingsProvider::class, $resolve('embeddings'));
    }
}
