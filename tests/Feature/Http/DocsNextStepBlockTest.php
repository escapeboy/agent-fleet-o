<?php

namespace Tests\Feature\Http;

use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Tests\TestCase;

/**
 * Covers the shared "Next step" block rendered by the docs layout at the
 * end of every public docs page.
 */
class DocsNextStepBlockTest extends TestCase
{
    // No RefreshDatabase — every page under test is a pure render.

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_agents_and_getting_started_docs_render_next_step_block(): void
    {
        foreach (['/docs/agents', '/docs/getting-started'] as $path) {
            $response = $this->get($path);

            $response->assertOk();
            $response->assertSee('data-docs-next-step', false);
            $response->assertSee('data-cta-location="docs_next_step"', false);
            $response->assertSee('href="'.route('templates.index').'"', false);
            $response->assertSee('data-analytics-label="browse_templates"', false);
            $response->assertSee('data-analytics-label="sign_up"', false);
            $response->assertSee('data-analytics-label="self_host_github"', false);
            $response->assertSee('data-analytics-event="cta_click"', false);
            $response->assertSee('href="https://github.com/escapeboy/agent-fleet-o"', false);
            $response->assertSee('Next step');
        }
    }

    public function test_docs_page_renders_without_register_route(): void
    {
        $this->removeRoute('register');

        $html = view('docs.agents')->render();

        $this->assertStringContainsString('data-docs-next-step', $html);
        $this->assertStringNotContainsString('data-analytics-label="sign_up"', $html);
        $this->assertStringContainsString('data-analytics-label="browse_templates"', $html);
        $this->assertStringContainsString('data-analytics-label="self_host_github"', $html);
    }

    public function test_docs_page_renders_without_templates_route(): void
    {
        $this->removeRoute('templates.index');

        $html = view('docs.agents')->render();

        $this->assertStringContainsString('data-docs-next-step', $html);
        $this->assertStringNotContainsString('data-analytics-label="browse_templates"', $html);
        $this->assertStringContainsString('data-analytics-label="sign_up"', $html);
        $this->assertStringContainsString('data-analytics-label="self_host_github"', $html);
    }

    private function removeRoute(string $name): void
    {
        /** @var Router $router */
        $router = app('router');
        /** @var UrlGenerator $url */
        $url = app('url');

        $collection = new RouteCollection;
        foreach ($router->getRoutes()->getRoutes() as $route) {
            if ($route->getName() !== $name) {
                $collection->add($route);
            }
        }

        $router->setRoutes($collection);
        $url->setRoutes($collection);

        $this->assertFalse($router->has($name));
    }
}
