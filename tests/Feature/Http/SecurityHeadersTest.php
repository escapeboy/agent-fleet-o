<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Domain\Shared\Models\Team;
use App\Models\Artifact;
use App\Models\ArtifactVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_pages_without_their_own_csp_get_the_app_policy(): void
    {
        $csp = (string) $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
    }

    public function test_oauth_authorize_page_does_not_restrict_form_action(): void
    {
        $csp = (string) $this->get('/oauth/authorize')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringNotContainsString('form-action', $csp);
    }
}
