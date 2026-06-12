<?php

namespace Tests\Unit\Domain\Memory\Actions;

use App\Domain\Memory\Actions\RetrieveRelevantMemoriesAction;
use App\Domain\Memory\Models\Memory;
use Prism\Prism\Embeddings\Response as EmbeddingResponse;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\PrismFake;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The JSONB tag filter must not emit the bare `?|` operator on PostgreSQL: the `?`
 * collides with PDO placeholders and raises SQLSTATE[42601] ("syntax error at or
 * near ... tags $11| $12"). The fix uses the placeholder-safe jsonb_exists_any()
 * function form. Memory retrieval must also skip the embeddings call (and the
 * semantic clauses that depend on it) when the query is blank — otherwise OpenAI
 * rejects the empty input with a 400 "Invalid 'input[0]'".
 */
class RetrieveRelevantMemoriesTagFilterTest extends TestCase
{
    private function fakeEmbeddings(): PrismFake
    {
        $vector = array_fill(0, 1536, 0.1);

        // Query embeddings now route through EmbeddingService::embedForTeam,
        // which skips the call when no provider key is configured. Set one so
        // the faked Prism request is actually issued.
        config(['prism.providers.openai.api_key' => 'test-key']);

        return Prism::fake([
            new EmbeddingResponse(
                embeddings: [new Embedding($vector)],
                usage: new EmbeddingsUsage(tokens: 10),
                meta: new Meta(id: 'test', model: 'text-embedding-3-small'),
            ),
        ]);
    }

    public function test_pgsql_tag_filter_uses_placeholder_safe_function_not_bare_operator(): void
    {
        config(['database.default' => 'pgsql']);

        $action = new RetrieveRelevantMemoriesAction;
        $method = new ReflectionMethod($action, 'applyTagFilter');
        $method->setAccessible(true);

        $builder = Memory::query();
        $method->invoke($action, $builder, ['billing', 'auth']);

        $sql = $builder->toSql();
        $bindings = $builder->getBindings();

        // The bare `?|` operator (which collides with PDO `?` placeholders and
        // raises SQLSTATE[42601]) must be gone.
        $this->assertStringNotContainsString('?|', $sql);
        $this->assertStringContainsString('jsonb_exists_any', $sql);
        $this->assertStringContainsString('::text[]', $sql);

        // Tags are bound as plain values, not wrapped in a `{...}` array literal.
        $this->assertSame(['billing', 'auth'], $bindings);
    }

    public function test_tags_filtered_query_runs_without_sql_syntax_error(): void
    {
        // SQLite (test DB) takes the json_each fallback; this asserts a tag-filtered
        // retrieval executes end-to-end without raising a SQL syntax error.
        $this->fakeEmbeddings();

        $action = new RetrieveRelevantMemoriesAction;

        $results = $action->execute(
            agentId: 'agent-1',
            query: 'how do refunds work',
            tags: ['billing', 'auth'],
        );

        $this->assertCount(0, $results);
    }

    public function test_empty_query_skips_embedding_call(): void
    {
        $fake = $this->fakeEmbeddings();

        $action = new RetrieveRelevantMemoriesAction;

        $results = $action->execute(
            agentId: 'agent-1',
            query: '   ',
        );

        $this->assertCount(0, $results);
        // No embeddings request must have been issued for a blank query.
        $fake->assertRequest(function (array $recorded) {
            $this->assertEmpty($recorded);
        });
    }

    public function test_oversized_query_is_truncated_before_embedding(): void
    {
        config(['memory.embedding_max_input_tokens' => 8192]);
        $fake = $this->fakeEmbeddings();

        $action = new RetrieveRelevantMemoriesAction;

        $action->execute(
            agentId: 'agent-1',
            query: str_repeat('word ', 12_000),
        );

        $fake->assertRequest(function (array $recorded) {
            $this->assertNotEmpty($recorded);
            // floor(8192 * 4 * 0.95) = 31129
            $this->assertLessThanOrEqual(31129, mb_strlen($recorded[0]->inputs()[0]));
        });
    }
}
