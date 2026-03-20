<?php

namespace Tests\Unit\Domain\Memory;

use App\Domain\Memory\Adapters\SupabaseVectorAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupabaseVectorAdapterTest extends TestCase
{
    private SupabaseVectorAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new SupabaseVectorAdapter(
            projectUrl: 'https://test.supabase.co',
            serviceRoleKey: 'test-service-role-key',
        );
    }

    public function test_store_inserts_memory_and_returns_uuid(): void
    {
        Http::fake([
            'test.supabase.co/rest/v1/fleetq_memories*' => Http::response(
                [['id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']],
                201
            ),
        ]);

        $embedding = array_fill(0, 1536, 0.1);
        $id = $this->adapter->store('Test memory content', $embedding, ['agent_id' => 'agent-1']);

        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $id);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/rest/v1/fleetq_memories')
                && $request->hasHeader('Authorization')
                && str_contains($request->body(), 'Test memory content');
        });
    }

    public function test_store_throws_on_http_failure(): void
    {
        Http::fake([
            'test.supabase.co/rest/v1/fleetq_memories*' => Http::response(['error' => 'bad'], 400),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/store failed/');

        $this->adapter->store('content', array_fill(0, 1536, 0.0));
    }

    public function test_search_calls_rpc_and_returns_results(): void
    {
        $fakeResults = [
            ['id' => 'uuid-1', 'content' => 'First memory', 'similarity' => 0.95, 'metadata' => []],
            ['id' => 'uuid-2', 'content' => 'Second memory', 'similarity' => 0.88, 'metadata' => ['agent_id' => 'a1']],
        ];

        Http::fake([
            'test.supabase.co/rest/v1/rpc/fleetq_match_memories' => Http::response($fakeResults, 200),
        ]);

        $results = $this->adapter->search(array_fill(0, 1536, 0.1), 0.78, 10);

        $this->assertCount(2, $results);
        $this->assertSame('uuid-1', $results[0]['id']);
        $this->assertSame(0.95, $results[0]['similarity']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'rpc/fleetq_match_memories')
                && str_contains($request->body(), 'match_threshold');
        });
    }

    public function test_search_throws_on_http_failure(): void
    {
        Http::fake([
            'test.supabase.co/rest/v1/rpc/fleetq_match_memories' => Http::response(null, 500),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/search failed/');

        $this->adapter->search(array_fill(0, 1536, 0.0));
    }

    public function test_delete_calls_delete_endpoint(): void
    {
        Http::fake([
            'test.supabase.co/rest/v1/fleetq_memories*' => Http::response(null, 204),
        ]);

        $this->adapter->delete('some-uuid');

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), 'fleetq_memories');
        });
    }

    public function test_get_setup_sql_replaces_embedding_dimension_placeholder(): void
    {
        $sql = SupabaseVectorAdapter::getSetupSql(768);

        $this->assertStringNotContainsString('{{EMBEDDING_DIMENSION}}', $sql);
        $this->assertStringContainsString('768', $sql);
        $this->assertStringContainsString('fleetq_memories', $sql);
        $this->assertStringContainsString('fleetq_match_memories', $sql);
    }

    public function test_get_setup_sql_defaults_to_1536_dimensions(): void
    {
        $sql = SupabaseVectorAdapter::getSetupSql();

        $this->assertStringContainsString('1536', $sql);
    }
}
