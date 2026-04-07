<?php

namespace Tests\Feature\Website;

use App\Domain\Shared\Models\Team;
use App\Domain\Website\Actions\GenerateWebsiteFromPromptAction;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GenerateWebsiteTest extends TestCase
{
    use RefreshDatabase;

    private function mockGateway(string $jsonResponse): void
    {
        $mockGateway = Mockery::mock(AiGatewayInterface::class);
        $mockGateway->shouldReceive('complete')
            ->andReturn(new AiResponseDTO(
                content: $jsonResponse,
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 500, completionTokens: 1000, costCredits: 15),
                provider: 'anthropic',
                model: 'claude-haiku-4-5-20251001',
                latencyMs: 800,
            ));

        $this->app->instance(AiGatewayInterface::class, $mockGateway);
    }

    public function test_generates_website_with_pages(): void
    {
        $team = Team::factory()->create();

        $this->mockGateway(json_encode([
            'pages' => [
                ['slug' => 'index', 'title' => 'Home', 'page_type' => 'landing', 'html' => '<div>Welcome</div>'],
                ['slug' => 'about', 'title' => 'About', 'page_type' => 'page', 'html' => '<div>About us</div>'],
            ],
        ]));

        $website = app(GenerateWebsiteFromPromptAction::class)->execute(
            team: $team,
            prompt: 'A small bakery website',
            name: 'Sweet Bakery',
        );

        $this->assertSame('Sweet Bakery', $website->name);
        $this->assertSame('sweet-bakery', $website->slug);
        $this->assertCount(2, $website->pages);
    }

    public function test_navigation_is_injected_into_every_page(): void
    {
        $team = Team::factory()->create();

        $this->mockGateway(json_encode([
            'pages' => [
                ['slug' => 'index', 'title' => 'Home', 'page_type' => 'landing', 'html' => '<div>Welcome</div>'],
                ['slug' => 'menu', 'title' => 'Menu', 'page_type' => 'page', 'html' => '<div>Our menu</div>'],
                ['slug' => 'contact', 'title' => 'Contact', 'page_type' => 'page', 'html' => '<div>Contact us</div>'],
            ],
        ]));

        $website = app(GenerateWebsiteFromPromptAction::class)->execute(
            team: $team,
            prompt: 'A restaurant website',
            name: 'La Maison',
        );

        foreach ($website->pages as $page) {
            $this->assertStringContainsString('<nav', $page->exported_html);
            $this->assertStringContainsString('href="/index"', $page->exported_html);
            $this->assertStringContainsString('href="/menu"', $page->exported_html);
            $this->assertStringContainsString('href="/contact"', $page->exported_html);
        }
    }

    public function test_contact_form_is_injected_on_landing_page(): void
    {
        $team = Team::factory()->create();

        $this->mockGateway(json_encode([
            'pages' => [
                ['slug' => 'index', 'title' => 'Home', 'page_type' => 'landing', 'html' => '<div>Welcome</div>'],
                ['slug' => 'about', 'title' => 'About', 'page_type' => 'page', 'html' => '<div>About</div>'],
            ],
        ]));

        $website = app(GenerateWebsiteFromPromptAction::class)->execute(
            team: $team,
            prompt: 'A SaaS landing page',
            name: 'My App',
        );

        $landingPage = $website->pages->firstWhere('slug', 'index');
        $this->assertStringContainsString('<form', $landingPage->exported_html);
        $this->assertStringContainsString('/api/public/sites/my-app/forms/', $landingPage->exported_html);
    }

    public function test_contact_form_is_injected_on_contact_page(): void
    {
        $team = Team::factory()->create();

        $this->mockGateway(json_encode([
            'pages' => [
                ['slug' => 'index', 'title' => 'Home', 'page_type' => 'page', 'html' => '<div>Welcome</div>'],
                ['slug' => 'contact', 'title' => 'Contact', 'page_type' => 'page', 'html' => '<div><h1>Contact us</h1></div>'],
            ],
        ]));

        $website = app(GenerateWebsiteFromPromptAction::class)->execute(
            team: $team,
            prompt: 'A company website',
            name: 'Acme Corp',
        );

        $contactPage = $website->pages->firstWhere('slug', 'contact');
        $this->assertStringContainsString('<form', $contactPage->exported_html);
        $this->assertStringContainsString('method="POST"', $contactPage->exported_html);
    }

    public function test_regular_pages_do_not_get_contact_form(): void
    {
        $team = Team::factory()->create();

        $this->mockGateway(json_encode([
            'pages' => [
                ['slug' => 'index', 'title' => 'Home', 'page_type' => 'page', 'html' => '<div>Welcome to our company</div>'],
                ['slug' => 'team', 'title' => 'Team', 'page_type' => 'page', 'html' => '<div>Our team members</div>'],
            ],
        ]));

        $website = app(GenerateWebsiteFromPromptAction::class)->execute(
            team: $team,
            prompt: 'A company profile website',
            name: 'Acme Inc',
        );

        $teamPage = $website->pages->firstWhere('slug', 'team');
        $this->assertStringNotContainsString('<form', $teamPage->exported_html);
    }

    public function test_throws_on_invalid_ai_response(): void
    {
        $team = Team::factory()->create();

        $this->mockGateway('this is not json at all');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to parse AI response as JSON');

        app(GenerateWebsiteFromPromptAction::class)->execute(
            team: $team,
            prompt: 'A website',
            name: 'My Site',
        );
    }

    public function test_strips_markdown_fences_from_ai_response(): void
    {
        $team = Team::factory()->create();

        $json = json_encode([
            'pages' => [
                ['slug' => 'index', 'title' => 'Home', 'page_type' => 'page', 'html' => '<div>Hello</div>'],
            ],
        ]);

        $this->mockGateway("```json\n{$json}\n```");

        $website = app(GenerateWebsiteFromPromptAction::class)->execute(
            team: $team,
            prompt: 'A simple site',
            name: 'Simple Site',
        );

        $this->assertCount(1, $website->pages);
    }

    public function test_website_slug_matches_name(): void
    {
        $team = Team::factory()->create();

        $this->mockGateway(json_encode([
            'pages' => [
                ['slug' => 'index', 'title' => 'Home', 'page_type' => 'landing', 'html' => '<div>Welcome</div>'],
            ],
        ]));

        $website = app(GenerateWebsiteFromPromptAction::class)->execute(
            team: $team,
            prompt: 'A tech startup website',
            name: 'Tech Startup Co.',
        );

        $this->assertSame('tech-startup-co', $website->slug);
    }
}
