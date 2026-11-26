<?php

namespace Tests\Feature\View\Components;

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The cloud edition consumes these landing components and may not register the Fortify `register` route.
 */
class LandingRegisterRouteTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function components(): array
    {
        return [
            'hero' => ['<x-landing.hero />'],
            'hero with agent cta' => ['<x-landing.hero agentCtaHref="/docs" />'],
            'cta' => ['<x-landing.cta heading="Ready?" subtext="Go" secondaryLabel="Docs" secondaryHref="/docs" />'],
            'how-it-works' => ['<x-landing.how-it-works />'],
        ];
    }

    #[DataProvider('components')]
    public function test_renders_without_register_route(string $template): void
    {
        $this->forgetRoute('register');

        $html = Blade::render($template);

        $this->assertStringContainsString('href="'.url('/').'"', $html);
    }

    #[DataProvider('components')]
    public function test_links_to_register_when_route_exists(string $template): void
    {
        $this->assertTrue(Route::has('register'));

        $html = Blade::render($template);

        $this->assertStringContainsString('href="'.route('register').'"', $html);
    }

    public function test_explicit_cta_href_wins(): void
    {
        $this->forgetRoute('register');

        $html = Blade::render('<x-landing.hero ctaHref="/somewhere" />');

        $this->assertStringContainsString('href="/somewhere"', $html);
    }

    private function forgetRoute(string $name): void
    {
        $router = app('router');
        $collection = new RouteCollection;

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if ($route->getName() !== $name) {
                $collection->add($route);
            }
        }

        $router->setRoutes($collection);
        app('url')->setRoutes($collection);

        $this->assertFalse(Route::has($name));
    }
}
