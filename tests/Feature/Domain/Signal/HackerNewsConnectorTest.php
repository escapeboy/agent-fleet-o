<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Connectors\HackerNewsConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HackerNewsConnectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_polls_top_feed_and_ingests_stories(): void
    {
        Queue::fake();
        $team = Team::factory()->create();

        Http::fake([
            'hacker-news.firebaseio.com/v0/topstories.json' => Http::response([101, 102]),
            'hacker-news.firebaseio.com/v0/item/101.json' => Http::response([
                'id' => 101, 'type' => 'story', 'title' => 'Show HN: FleetQ', 'url' => 'https://example.com/a', 'score' => 120, 'by' => 'alice', 'descendants' => 4,
            ]),
            'hacker-news.firebaseio.com/v0/item/102.json' => Http::response([
                'id' => 102, 'type' => 'story', 'title' => 'Postgres tips', 'url' => 'https://example.com/b', 'score' => 80, 'by' => 'bob', 'descendants' => 2,
            ]),
        ]);

        $signals = app(HackerNewsConnector::class)->poll([
            '_team_id' => $team->id,
            'feed' => 'top',
        ]);

        $this->assertCount(2, $signals);
        $this->assertDatabaseHas('signals', [
            'team_id' => $team->id,
            'source_type' => 'hacker_news',
            'source_native_id' => '101',
        ]);
    }

    public function test_min_score_filters_low_scoring_stories(): void
    {
        Queue::fake();
        $team = Team::factory()->create();

        Http::fake([
            'hacker-news.firebaseio.com/v0/topstories.json' => Http::response([201, 202]),
            'hacker-news.firebaseio.com/v0/item/201.json' => Http::response([
                'id' => 201, 'type' => 'story', 'title' => 'High score', 'score' => 200, 'url' => 'https://example.com/x',
            ]),
            'hacker-news.firebaseio.com/v0/item/202.json' => Http::response([
                'id' => 202, 'type' => 'story', 'title' => 'Low score', 'score' => 5, 'url' => 'https://example.com/y',
            ]),
        ]);

        $signals = app(HackerNewsConnector::class)->poll([
            '_team_id' => $team->id,
            'feed' => 'top',
            'min_score' => 100,
        ]);

        $this->assertCount(1, $signals);
        $this->assertDatabaseMissing('signals', ['source_native_id' => '202']);
    }

    public function test_unknown_feed_returns_empty_without_http(): void
    {
        Http::fake();
        $team = Team::factory()->create();

        $signals = app(HackerNewsConnector::class)->poll([
            '_team_id' => $team->id,
            'feed' => 'bogus',
        ]);

        $this->assertSame([], $signals);
        Http::assertNothingSent();
    }
}
