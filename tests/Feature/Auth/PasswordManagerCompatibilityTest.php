<?php

namespace Tests\Feature\Auth;

use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression guard for password-manager-friendly markup.
 *
 * 1Password's "compatible website design" guide and the W3C HTML autofill spec
 * prescribe specific autocomplete tokens on each auth field. Removing them is
 * almost always a mistake — autofill / strong-password generation breaks silently.
 *
 * Spec: https://developer.1password.com/docs/web/compatible-website-design/
 *       https://html.spec.whatwg.org/multipage/form-control-infrastructure.html#autofill
 *       https://w3c.github.io/webappsec-change-password-url/
 */
class PasswordManagerCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_advertises_username_and_current_password(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('autocomplete="username"', false);
        $response->assertSee('autocomplete="current-password"', false);
    }

    public function test_register_page_advertises_new_password_on_both_password_fields(): void
    {
        $response = $this->get('/register');

        $response->assertOk();
        $response->assertSee('autocomplete="name"', false);
        $response->assertSee('autocomplete="username"', false);
        // Both password and password_confirmation must hint new-password — without it
        // 1Password's "suggest strong password" prompt does not trigger on signup.
        $this->assertEquals(
            2,
            substr_count($response->getContent(), 'autocomplete="new-password"'),
            'register form must use autocomplete="new-password" on both password fields',
        );
    }

    public function test_well_known_change_password_redirects_to_profile_security_section(): void
    {
        // W3C spec: https://w3c.github.io/webappsec-change-password-url/
        // 1Password and every modern browser look for /.well-known/change-password
        // and follow the redirect to the actual change-password UI.
        $response = $this->get('/.well-known/change-password');

        $response->assertStatus(302);
        $location = $response->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertStringContainsString('/profile', $location);
        $this->assertStringContainsString('#security', $location);
    }

    public function test_well_known_change_password_works_unauthenticated_so_password_managers_can_probe(): void
    {
        // Spec requires 2xx or 3xx without forcing the user to authenticate first.
        // Auth happens on the redirect target, not on the well-known URL itself.
        $response = $this->withoutMiddleware()->get('/.well-known/change-password');

        $this->assertContains($response->getStatusCode(), [200, 301, 302, 303, 307, 308]);
    }

    public function test_authenticated_change_password_form_renders_hidden_username_companion(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id, 'email' => 'pm-compat@example.test']);
        $team->users()->attach($user, ['role' => 'owner']);

        $response = $this->actingAs($user)->get('/profile');

        $response->assertOk();
        // Hidden username companion lets password managers tie the new password to the right account.
        $response->assertSee('name="username"', false);
        $response->assertSee('autocomplete="username"', false);
        $response->assertSee('autocomplete="current-password"', false);
        $response->assertSee('autocomplete="new-password"', false);
    }
}
