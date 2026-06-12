<?php

namespace Tests\Unit\Domain\Memory\Actions;

use App\Domain\Memory\Actions\RetrieveRelevantMemoriesAction;
use App\Infrastructure\AI\Services\EmbeddingService;
use Prism\Prism\Embeddings\Response as EmbeddingResponse;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\PrismFake;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Memory-retrieval embedding input must be capped at the embedding model's token
 * limit so a long query never trips OpenAI's "maximum input length is 8192 tokens"
 * 400. Two embedding paths exist and both must truncate:
 *   - base RetrieveRelevantMemoriesAction::generateEmbedding (direct Prism call)
 *   - EmbeddingService::embed (used by the cloud CloudRetrieveRelevantMemoriesAction)
 */
class RetrieveRelevantMemoriesEmbeddingTruncationTest extends TestCase
{
    /** Char budget that the implementation truncates to for the default 8192-token cap. */
    private const CHAR_BUDGET = 31129; // floor(8192 * 4 * 0.95)

    private function fakeEmbeddings(): PrismFake
    {
        $vector = array_fill(0, 1536, 0.1);

        // Query embeddings route through EmbeddingService::embedForTeam, which
        // skips the call when no provider key is configured. Set one so the
        // faked Prism request is actually issued.
        config(['prism.providers.openai.api_key' => 'test-key']);

        return Prism::fake([
            new EmbeddingResponse(
                embeddings: [new Embedding($vector)],
                usage: new EmbeddingsUsage(tokens: 10),
                meta: new Meta(id: 'test', model: 'text-embedding-3-small'),
            ),
        ]);
    }

    /** ~12k tokens at 4 chars/token — well over the 8192 cap. */
    private function longQuery(): string
    {
        return str_repeat('word ', 12_000);
    }

    private function invokeBaseGenerateEmbedding(string $text): void
    {
        // Instantiate the base action directly so the container's cloud override
        // (CloudRetrieveRelevantMemoriesAction) does not shadow the path under test.
        $action = new RetrieveRelevantMemoriesAction;
        $method = new ReflectionMethod($action, 'generateEmbedding');
        $method->setAccessible(true);
        // generateEmbedding is now team-aware; null teamId falls back to the
        // platform/configured key, which is what these truncation cases assert.
        $method->invoke($action, $text, null);
    }

    public function test_embedding_service_truncates_long_input(): void
    {
        config(['memory.embedding_max_input_tokens' => 8192]);
        $fake = $this->fakeEmbeddings();

        (new EmbeddingService('openai', 'text-embedding-3-small'))->embed($this->longQuery());

        $fake->assertRequest(function (array $recorded) {
            $this->assertNotEmpty($recorded);
            $this->assertLessThanOrEqual(self::CHAR_BUDGET, mb_strlen($recorded[0]->inputs()[0]));
        });
    }

    public function test_embedding_service_passes_short_input_unchanged(): void
    {
        config(['memory.embedding_max_input_tokens' => 8192]);
        $fake = $this->fakeEmbeddings();

        (new EmbeddingService('openai', 'text-embedding-3-small'))->embed('reset my password');

        $fake->assertRequest(function (array $recorded) {
            $this->assertSame('reset my password', $recorded[0]->inputs()[0]);
        });
    }

    public function test_base_action_truncates_long_query(): void
    {
        config(['memory.embedding_max_input_tokens' => 8192]);
        $fake = $this->fakeEmbeddings();

        $this->invokeBaseGenerateEmbedding($this->longQuery());

        $fake->assertRequest(function (array $recorded) {
            $this->assertNotEmpty($recorded);
            $this->assertLessThanOrEqual(self::CHAR_BUDGET, mb_strlen($recorded[0]->inputs()[0]));
        });
    }

    public function test_base_action_passes_short_query_unchanged(): void
    {
        config(['memory.embedding_max_input_tokens' => 8192]);
        $fake = $this->fakeEmbeddings();

        $this->invokeBaseGenerateEmbedding('reset my password');

        $fake->assertRequest(function (array $recorded) {
            $this->assertSame('reset my password', $recorded[0]->inputs()[0]);
        });
    }
}
