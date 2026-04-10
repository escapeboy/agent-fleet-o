<?php

namespace Tests\Feature\Website;

use App\Domain\Shared\Models\Team;
use App\Domain\Website\Actions\CreateWebsiteAction;
use App\Domain\Website\Actions\CreateWebsitePageAction;
use App\Domain\Website\Actions\EnhanceWebsiteNavigationAction;
use App\Domain\Website\Actions\UpdateWebsiteAction;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Dedicated throttle test for the public form submission endpoint.
 *
 * The sibling `PublicSiteControllerTest` class disables ThrottleRequests
 * middleware to avoid counter state leaking across tests. That leaves a
 * coverage gap — the `throttle:10,1` on `/api/public/sites/{slug}/forms/
 * {formId}` is trusted to work via Laravel's built-in middleware, but no
 * test ever verifies it actually fires.
 *
 * This class keeps throttle enabled and isolates state by flushing both
 * Cache and the RateLimiter before each test, and by using a unique fake
 * source IP per test via `withServerVariables`.
 */
class PublicSiteControllerThrottleTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Website $website;

    private string $formId;

    /** Unique source IP per test method to guarantee isolated throttle counters. */
    private string $sourceIp;

    /** Static counter so every test in this class gets a unique /24 /8 combo. */
    private static int $ipCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Nuke EVERY cached store, not just the default. ThrottleRequests
        // middleware may use a limiter-specific store that Cache::flush()
        // alone doesn't touch. Iterating the cache manager's internal
        // store list and flushing each one guarantees a clean slate no
        // matter which store the rate limiter keys into.
        $manager = $this->app->make('cache');
        foreach (array_keys(config('cache.stores')) as $storeName) {
            try {
                $manager->store($storeName)->flush();
            } catch (\Throwable) {
                // Some stores (e.g. dynamodb in tests) may not be wired up.
            }
        }
        RateLimiter::clear('form-submit-test');

        // Unique IP per test method using a monotonic counter. Combined
        // with the aggressive cache flush this guarantees no throttle
        // state leaks from prior tests.
        self::$ipCounter++;
        $third = intdiv(self::$ipCounter, 250);
        $fourth = (self::$ipCounter % 250) + 1;
        $this->sourceIp = sprintf('198.51.%d.%d', $third, $fourth);

        $this->team = Team::factory()->create();

        $this->website = app(CreateWebsiteAction::class)->execute($this->team, [
            'name' => 'Test Site',
            'slug' => 'test-site',
        ]);

        $page = app(CreateWebsitePageAction::class)->execute($this->website, [
            'slug' => 'contact',
            'title' => 'Contact',
            'page_type' => 'page',
        ]);
        $page->update(['exported_html' => '<div>Contact us</div>']);

        app(EnhanceWebsiteNavigationAction::class)->execute($this->website);
        app(UpdateWebsiteAction::class)->execute($this->website, ['status' => WebsiteStatus::Published]);

        $page->refresh();
        $this->formId = (string) $page->form_id;
    }

    public function test_throttle_allows_up_to_ten_requests_per_minute(): void
    {
        // Ten requests from a dedicated IP should all succeed (200).
        for ($i = 0; $i < 10; $i++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => $this->sourceIp])
                ->postJson("/api/public/sites/test-site/forms/{$this->formId}", [
                    'fields' => ['name' => 'Alice '.$i],
                ]);

            $response->assertStatus(200);
        }
    }

    public function test_throttle_blocks_eleventh_request_with_429(): void
    {
        // Saturate the counter for a dedicated IP.
        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => $this->sourceIp])
                ->postJson("/api/public/sites/test-site/forms/{$this->formId}", [
                    'fields' => ['name' => 'Spammer '.$i],
                ]);
        }

        // The eleventh request must be blocked.
        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->sourceIp])
            ->postJson("/api/public/sites/test-site/forms/{$this->formId}", [
                'fields' => ['name' => 'Spammer 11'],
            ]);

        $response->assertStatus(429);
    }

    public function test_throttle_is_keyed_per_ip(): void
    {
        $ipA = $this->sourceIp;
        // Bump the counter again for a guaranteed-distinct second IP.
        self::$ipCounter++;
        $third = intdiv(self::$ipCounter, 250);
        $fourth = (self::$ipCounter % 250) + 1;
        $ipB = sprintf('198.51.%d.%d', $third, $fourth);

        // Client A saturates their bucket.
        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => $ipA])
                ->postJson("/api/public/sites/test-site/forms/{$this->formId}", [
                    'fields' => ['name' => 'A '.$i],
                ]);
        }

        // Client A's next request is blocked.
        $this->withServerVariables(['REMOTE_ADDR' => $ipA])
            ->postJson("/api/public/sites/test-site/forms/{$this->formId}", [
                'fields' => ['name' => 'A overflow'],
            ])
            ->assertStatus(429);

        // But client B (different IP) is still fine.
        $this->withServerVariables(['REMOTE_ADDR' => $ipB])
            ->postJson("/api/public/sites/test-site/forms/{$this->formId}", [
                'fields' => ['name' => 'B first'],
            ])
            ->assertStatus(200);
    }
}
