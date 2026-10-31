<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Domain\Shared\Models\Team;
use App\Domain\Website\Enums\WebsitePageStatus;
use App\Domain\Website\Enums\WebsitePageType;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsitePage;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Artifact;
use App\Models\ArtifactVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_artifact_render_keeps_its_own_stricter_csp(): void
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'Headers Test',
            'slug' => 'headers-test-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $team->id]);

        $artifact = Artifact::create(['team_id' => $team->id, 'name' => 'page', 'type' => 'html']);
        ArtifactVersion::create([
            'team_id' => $team->id,
            'artifact_id' => $artifact->id,
            'version' => 1,
            'content' => '<script>alert(1)</script><p>hi</p>',
        ]);

        $response = $this->actingAs($user)->get("/artifacts/{$artifact->id}/render");

        $response->assertOk();
        $response->assertHeader('Content-Security-Policy', "script-src 'none'; object-src 'none'");
        $this->assertStringNotContainsString('unsafe-inline', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_base_policy_keeps_form_action_self_off_the_authorize_page_only(): void
    {
        // Instantiate the base class directly: a deployment may register a subclass globally.
        $middleware = new SecurityHeaders;
        $handle = fn (string $uri): string => (string) $middleware
            ->handle(Request::create($uri), fn () => response('ok'))
            ->headers->get('Content-Security-Policy');

        $csp = $handle('/login');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
    }

    public function test_oauth_authorize_page_does_not_restrict_form_action(): void
    {
        $csp = (string) $this->get('/oauth/authorize')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringNotContainsString('form-action', $csp);
    }

    public function test_website_preview_csp_allows_https_assets_but_not_inline_script(): void
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'Preview Test',
            'slug' => 'preview-test-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $team->id]);

        $website = Website::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'name' => 'Demo',
            'slug' => 'demo',
            'status' => WebsiteStatus::Published,
        ]);
        $page = WebsitePage::create([
            'website_id' => $website->id,
            'team_id' => $team->id,
            'slug' => 'home',
            'title' => 'Home',
            'page_type' => WebsitePageType::Page,
            'status' => WebsitePageStatus::Draft,
            'exported_html' => '<p>x</p>',
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($user)->get("/websites/{$website->id}/pages/{$page->id}/preview");

        $response->assertOk();
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression('/(^|; )img-src [^;]*https:/', $csp);
        $this->assertMatchesRegularExpression('/(^|; )font-src [^;]*https:/', $csp);
        $this->assertMatchesRegularExpression('/(^|; )style-src [^;]*https:/', $csp);
        $this->assertMatchesRegularExpression("/(^|; )script-src 'nonce-[^']+'(;|$)/", $csp);
        $this->assertStringNotContainsString('unsafe-inline\'; img', $csp);
        $this->assertDoesNotMatchRegularExpression("/script-src [^;]*'unsafe-(inline|eval)'/", $csp);
    }
}
