<?php

namespace Tests\Feature\Domain\Website;

use App\Domain\Shared\Models\Team;
use App\Domain\Website\Actions\CreateWebsiteAction;
use App\Domain\Website\Actions\CreateWebsitePageAction;
use App\Domain\Website\Actions\DeleteWebsiteAction;
use App\Domain\Website\Actions\PublishWebsitePageAction;
use App\Domain\Website\Actions\UpdateWebsitePageAction;
use App\Domain\Website\Enums\WebsitePageStatus;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => 'owner']);
    }

    public function test_can_create_website(): void
    {
        $website = app(CreateWebsiteAction::class)->execute($this->team, [
            'name' => 'My Site',
            'slug' => 'my-site',
        ]);

        $this->assertNotNull($website->id);
        $this->assertEquals('My Site', $website->name);
        $this->assertEquals($this->team->id, $website->team_id);
        $this->assertEquals(WebsiteStatus::Draft, $website->status);
    }

    public function test_slug_is_auto_generated_from_name(): void
    {
        $website = app(CreateWebsiteAction::class)->execute($this->team, [
            'name' => 'My Awesome Site',
        ]);

        $this->assertEquals('my-awesome-site', $website->slug);
    }

    public function test_can_create_website_page(): void
    {
        $website = app(CreateWebsiteAction::class)->execute($this->team, ['name' => 'Test Site']);

        $page = app(CreateWebsitePageAction::class)->execute($website, [
            'slug' => 'index',
            'title' => 'Home',
            'page_type' => 'landing',
        ]);

        $this->assertNotNull($page->id);
        $this->assertEquals($website->id, $page->website_id);
        $this->assertEquals($this->team->id, $page->team_id);
        $this->assertEquals('index', $page->slug);
    }

    public function test_publish_page_requires_exported_html(): void
    {
        $this->expectException(\RuntimeException::class);

        $website = app(CreateWebsiteAction::class)->execute($this->team, ['name' => 'Test Site']);
        $page = app(CreateWebsitePageAction::class)->execute($website, [
            'slug' => 'about',
            'title' => 'About',
            'page_type' => 'page',
        ]);

        app(PublishWebsitePageAction::class)->execute($page);
    }

    public function test_can_publish_page_with_html(): void
    {
        $website = app(CreateWebsiteAction::class)->execute($this->team, ['name' => 'Test Site']);
        $page = app(CreateWebsitePageAction::class)->execute($website, [
            'slug' => 'index',
            'title' => 'Home',
            'page_type' => 'landing',
        ]);

        app(UpdateWebsitePageAction::class)->execute($page, [
            'exported_html' => '<h1>Hello World</h1>',
            'exported_css' => '',
        ]);

        $published = app(PublishWebsitePageAction::class)->execute($page->fresh());

        $this->assertEquals(WebsitePageStatus::Published, $published->status);
        $this->assertNotNull($published->published_at);
    }

    public function test_delete_website_soft_deletes(): void
    {
        $website = app(CreateWebsiteAction::class)->execute($this->team, ['name' => 'Deletable Site']);
        $id = $website->id;

        app(DeleteWebsiteAction::class)->execute($website);

        $this->assertNull(Website::find($id));
        $this->assertNotNull(Website::withTrashed()->find($id));
    }

    public function test_website_has_pages_relation(): void
    {
        $website = app(CreateWebsiteAction::class)->execute($this->team, ['name' => 'Site With Pages']);

        app(CreateWebsitePageAction::class)->execute($website, [
            'slug' => 'index',
            'title' => 'Home',
            'page_type' => 'landing',
        ]);

        $this->assertEquals(1, $website->pages()->count());
    }
}
