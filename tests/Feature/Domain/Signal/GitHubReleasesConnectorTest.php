<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Connectors\GitHubReleasesConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GitHubReleasesConnectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_polls_releases_atom_and_ingests(): void
    {
        Queue::fake();
        $team = Team::factory()->create();

        $atom = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <entry>
    <id>tag:github.com,2008:Repository/123/v13.2.0</id>
    <title>v13.2.0</title>
    <updated>2026-05-20T10:00:00Z</updated>
    <link rel="alternate" type="text/html" href="https://github.com/laravel/framework/releases/tag/v13.2.0"/>
    <content type="html">Bug fixes and improvements.</content>
    <author><name>taylorotwell</name></author>
  </entry>
  <entry>
    <id>tag:github.com,2008:Repository/123/v13.1.0</id>
    <title>v13.1.0</title>
    <updated>2026-05-10T10:00:00Z</updated>
    <link rel="alternate" type="text/html" href="https://github.com/laravel/framework/releases/tag/v13.1.0"/>
    <content type="html">Initial 13.1 release.</content>
    <author><name>taylorotwell</name></author>
  </entry>
</feed>
XML;

        Http::fake([
            'github.com/laravel/framework/releases.atom' => Http::response($atom, 200, ['Content-Type' => 'application/atom+xml']),
        ]);

        $signals = app(GitHubReleasesConnector::class)->poll([
            '_team_id' => $team->id,
            'repo' => 'laravel/framework',
        ]);

        $this->assertCount(2, $signals);
        $this->assertDatabaseHas('signals', [
            'team_id' => $team->id,
            'source_type' => 'github_releases',
            'source_native_id' => 'tag:github.com,2008:Repository/123/v13.2.0',
        ]);
    }

    public function test_invalid_repo_returns_empty_without_http(): void
    {
        Http::fake();
        $team = Team::factory()->create();

        $signals = app(GitHubReleasesConnector::class)->poll([
            '_team_id' => $team->id,
            'repo' => 'not-a-valid-repo',
        ]);

        $this->assertSame([], $signals);
        Http::assertNothingSent();
    }
}
