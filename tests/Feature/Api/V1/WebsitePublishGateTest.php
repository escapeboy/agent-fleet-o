<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Website\Actions\CreateWebsiteAction;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;

class WebsitePublishGateTest extends ApiTestCase
{
    private function makeWebsite(): Website
    {
        return app(CreateWebsiteAction::class)->execute($this->team, [
            'name' => 'My Store',
            'slug' => 'my-store',
        ], $this->user);
    }

    public function test_publish_does_not_expose_site_publicly_by_default(): void
    {
        $this->actingAsApiUser();
        $website = $this->makeWebsite();

        $this->postJson("/api/v1/websites/{$website->id}/publish")
            ->assertStatus(200);

        $website->refresh();
        $this->assertSame(WebsiteStatus::Published, $website->status);
        // The publish flow must never auto-enable public apex exposure.
        $this->assertNotTrue($website->settings['allow_public'] ?? false);
    }

    public function test_publish_can_opt_in_to_public_exposure_explicitly(): void
    {
        $this->actingAsApiUser();
        $website = $this->makeWebsite();

        $this->postJson("/api/v1/websites/{$website->id}/publish", ['allow_public' => true])
            ->assertStatus(200);

        $website->refresh();
        $this->assertTrue($website->settings['allow_public']);
    }
}
