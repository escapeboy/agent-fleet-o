<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Google Analytics 4 in Consent Mode v2 BASIC: gtag.js is not requested, and no Google host is
 * contacted, until the visitor accepts. These tests pin what the server sends for the three
 * layouts (public, auth, app); the network behaviour is verified in a real browser.
 */
class AnalyticsConsentTest extends TestCase
{
    use RefreshDatabase;

    private const ID = 'G-TESTCONSENT';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function assertNoGoogleScriptTag(string $html): void
    {
        $this->assertSame(0, preg_match('#<script[^>]+src=["\'][^"\']*(googletagmanager|google-analytics)#i', $html));
        $this->assertSame(0, preg_match('#<(link|img|iframe)[^>]+(googletagmanager|google-analytics)#i', $html));
    }

    private function actAsTeamUser(): void
    {
        $user = User::factory()->create();
        $team = Team::create(['name' => 'T', 'slug' => 't', 'owner_id' => $user->id, 'settings' => []]);
        $user->update(['current_team_id' => $team->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user);
    }

    /** @return array<string, array{0: string}> */
    public static function layouts(): array
    {
        return ['public layout' => ['public'], 'auth layout' => ['auth'], 'app layout' => ['app']];
    }

    private function fetch(string $layout): string
    {
        if ($layout === 'app') {
            $this->actAsTeamUser();

            return (string) $this->get(route('dashboard'))->assertOk()->getContent();
        }

        if ($layout === 'auth') {
            // Used by the accept-terms and setup pages; rendered directly so the test needs no setup state.
            return Blade::render('<x-layouts.auth title="Test"><p>content</p></x-layouts.auth>');
        }

        return (string) $this->get(route('legal.privacy'))->assertOk()->getContent();
    }

    #[DataProvider('layouts')]
    public function test_no_googletagmanager_script_tag_without_consent(string $layout): void
    {
        config(['services.google_analytics.id' => self::ID]);

        $html = $this->fetch($layout);

        $this->assertNoGoogleScriptTag($html);
        $this->assertStringContainsString(self::ID, $html);
        // gtag.js is injected by grant() only, which runs on a stored accept or a click on Accept.
        $this->assertSame(1, substr_count($html, 'googletagmanager.com/gtag/js'));
        $this->assertTrue(strpos($html, 'function grant()') < strpos($html, 'googletagmanager.com/gtag/js'));
        $this->assertTrue(strpos($html, 'googletagmanager.com/gtag/js') < strpos($html, 'function revoke()'));
        $this->assertStringContainsString("window['ga-disable-' + ID] = true;", $html);
        $this->assertStringContainsString("analytics_storage: 'denied'", $html);
        $this->assertStringNotContainsString("ad_storage: 'granted'", $html);
        $this->assertStringContainsString("n.indexOf('_ga_') === 0", $html);
    }

    #[DataProvider('layouts')]
    public function test_banner_is_a_labelled_region_with_equal_buttons_and_the_truthful_text(string $layout): void
    {
        config(['services.google_analytics.id' => self::ID]);

        $html = $this->fetch($layout);

        $this->assertStringContainsString('data-cookie-banner role="region" aria-label="Cookie notice"', $html);
        $this->assertStringContainsString('If you reject, we only remember your choice in your browser and set no analytics cookies', $html);
        $this->assertStringContainsString(route('legal.privacy'), $html);
        $this->assertStringContainsString('data-cookie-settings', $html);
        $this->assertStringContainsString('v: 1', $html);
        $this->assertStringContainsString('new Date().toISOString()', $html);

        preg_match('#data-cookie-choice="accept" class="([^"]+)"#', $html, $accept);
        preg_match('#data-cookie-choice="reject" class="([^"]+)"#', $html, $reject);
        $this->assertNotEmpty($accept[1] ?? null);
        $this->assertSame($accept[1], $reject[1] ?? null);

        // Early in the DOM: before the page content.
        $this->assertLessThan(strpos($html, '<main') ?: strpos($html, '<p>content</p>'), strpos($html, 'data-cookie-banner'));
    }

    #[DataProvider('layouts')]
    public function test_nothing_is_rendered_without_a_measurement_id(string $layout): void
    {
        config(['services.google_analytics.id' => null]);

        $html = $this->fetch($layout);

        $this->assertStringNotContainsString('googletagmanager', $html);
        $this->assertStringNotContainsString('data-cookie-banner', $html);
        $this->assertStringNotContainsString('data-cookie-settings', $html);
    }

    public function test_csp_allows_google_only_when_an_id_is_configured(): void
    {
        config(['services.google_analytics.id' => null]);
        $this->assertStringNotContainsString('googletagmanager', (string) $this->get(route('legal.privacy'))->headers->get('Content-Security-Policy'));

        config(['services.google_analytics.id' => self::ID]);
        $csp = (string) $this->get(route('legal.privacy'))->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('https://www.googletagmanager.com', $csp);
        $this->assertStringContainsString('https://*.google-analytics.com', $csp);
    }

    public function test_cookie_policy_names_the_ga_cookies_only_when_ga_is_on(): void
    {
        config(['services.google_analytics.id' => self::ID]);
        $this->get(route('legal.cookies'))->assertOk()->assertSee('_ga', false)->assertSee('only after you click', false)->assertDontSee('No analytics cookies are set by FleetQ');

        config(['services.google_analytics.id' => null]);
        $this->get(route('legal.cookies'))->assertOk()->assertSee('No analytics cookies are set by FleetQ');
    }
}
