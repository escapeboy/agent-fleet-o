<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Connectors\RedditConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RedditConnectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_polls_subreddit_and_ingests_posts(): void
    {
        Queue::fake();
        $team = Team::factory()->create();

        Http::fake([
            'www.reddit.com/r/laravel/hot.json*' => Http::response([
                'data' => [
                    'children' => [
                        ['kind' => 't3', 'data' => [
                            'name' => 't3_aaa', 'title' => 'Laravel 13 released', 'url' => 'https://example.com/p1',
                            'permalink' => '/r/laravel/comments/aaa/laravel_13/', 'score' => 340, 'author' => 'taylor', 'num_comments' => 88,
                        ]],
                        ['kind' => 't3', 'data' => [
                            'name' => 't3_bbb', 'title' => 'Question about queues', 'url' => 'https://example.com/p2',
                            'permalink' => '/r/laravel/comments/bbb/queues/', 'score' => 12, 'author' => 'jane', 'num_comments' => 3,
                        ]],
                    ],
                ],
            ]),
        ]);

        $signals = app(RedditConnector::class)->poll([
            '_team_id' => $team->id,
            'subreddit' => 'laravel',
            'sort' => 'hot',
        ]);

        $this->assertCount(2, $signals);
        $this->assertDatabaseHas('signals', [
            'team_id' => $team->id,
            'source_type' => 'reddit',
            'source_native_id' => 't3_aaa',
        ]);
    }

    public function test_min_score_filters_posts(): void
    {
        Queue::fake();
        $team = Team::factory()->create();

        Http::fake([
            'www.reddit.com/r/laravel/top.json*' => Http::response([
                'data' => ['children' => [
                    ['kind' => 't3', 'data' => ['name' => 't3_hi', 'title' => 'Popular', 'score' => 500, 'url' => 'https://e.com/1']],
                    ['kind' => 't3', 'data' => ['name' => 't3_lo', 'title' => 'Unpopular', 'score' => 2, 'url' => 'https://e.com/2']],
                ]],
            ]),
        ]);

        $signals = app(RedditConnector::class)->poll([
            '_team_id' => $team->id,
            'subreddit' => 'laravel',
            'sort' => 'top',
            'time' => 'week',
            'min_score' => 100,
        ]);

        $this->assertCount(1, $signals);
        $this->assertDatabaseMissing('signals', ['source_native_id' => 't3_lo']);
    }

    public function test_invalid_subreddit_returns_empty_without_http(): void
    {
        Http::fake();
        $team = Team::factory()->create();

        $signals = app(RedditConnector::class)->poll([
            '_team_id' => $team->id,
            'subreddit' => 'bad/name space',
        ]);

        $this->assertSame([], $signals);
        Http::assertNothingSent();
    }
}
