<?php

namespace Tests\Feature\Search;

use App\Domain\Search\Contracts\WebSearchProviderInterface;
use App\Domain\Search\Exceptions\WebSearchUnavailableException;
use App\Domain\Search\Providers\SearxngWebSearchProvider;
use App\Domain\Search\Providers\SerperWebSearchProvider;
use App\Domain\Signal\Connectors\SearxngConnector;
use App\Mcp\Tools\Search\WebSearchTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Mockery;
use Tests\TestCase;

class WebSearchSeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_driver_resolves_to_searxng_provider(): void
    {
        config(['web_search.driver' => 'searxng']);
        $this->assertInstanceOf(SearxngWebSearchProvider::class, app(WebSearchProviderInterface::class));
    }

    public function test_serper_driver_resolves_to_serper_provider(): void
    {
        config(['web_search.driver' => 'serper']);
        $this->assertInstanceOf(SerperWebSearchProvider::class, app(WebSearchProviderInterface::class));
    }

    public function test_unknown_driver_throws(): void
    {
        config(['web_search.driver' => 'nope']);
        $this->expectException(\InvalidArgumentException::class);
        app(WebSearchProviderInterface::class);
    }

    public function test_serper_without_key_throws_unavailable(): void
    {
        config(['web_search.providers.serper.key' => null]);
        $this->expectException(WebSearchUnavailableException::class);
        (new SerperWebSearchProvider)->search('test');
    }

    public function test_searxng_provider_normalizes_results(): void
    {
        config(['web_search.providers.searxng.url' => 'http://searxng.local']);

        $connector = Mockery::mock(SearxngConnector::class);
        $connector->shouldReceive('search')->once()->andReturn([
            ['title' => 'A', 'url' => 'https://a.test', 'content' => 'snippet A', 'score' => 1.0, 'engine' => 'x'],
        ]);

        $provider = new SearxngWebSearchProvider($connector);
        $results = $provider->search('q', ['max_results' => 3]);

        $this->assertSame([['title' => 'A', 'url' => 'https://a.test', 'snippet' => 'snippet A']], $results);
    }

    public function test_searxng_provider_without_url_throws(): void
    {
        config(['web_search.providers.searxng.url' => null]);
        $connector = Mockery::mock(SearxngConnector::class);
        $provider = new SearxngWebSearchProvider($connector);

        $this->expectException(WebSearchUnavailableException::class);
        $provider->search('q');
    }

    public function test_web_search_tool_returns_normalized_results(): void
    {
        $fake = new class implements WebSearchProviderInterface
        {
            public function name(): string
            {
                return 'fake';
            }

            public function search(string $query, array $options = []): array
            {
                return [['title' => 'T', 'url' => 'https://t.test', 'snippet' => 'S']];
            }
        };
        app()->instance(WebSearchProviderInterface::class, $fake);

        $response = (new WebSearchTool)->handle(new Request(['query' => 'hello']));
        $data = json_decode((string) $response->content(), true);

        $this->assertSame('fake', $data['provider']);
        $this->assertSame(1, $data['count']);
        $this->assertSame('https://t.test', $data['results'][0]['url']);
    }

    public function test_web_search_tool_requires_query(): void
    {
        $response = (new WebSearchTool)->handle(new Request(['query' => '']));
        $this->assertStringContainsString('query is required', (string) $response->content());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
