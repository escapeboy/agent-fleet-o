<?php

namespace Tests\Feature\Domain\Integration;

use App\Domain\Credential\Models\Credential;
use App\Domain\Integration\Drivers\Sentry\SentryIntegrationDriver;
use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Covers the project-scoping branch in SentryIntegrationDriver::poll() —
 * config['project_id'] narrows the poll to one Sentry project; without it
 * the poll stays org-wide.
 */
class SentryIntegrationDriverPollTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $config
     */
    private function makeIntegration(array $config): Integration
    {
        $team = Team::factory()->create();
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'secret_data' => ['access_token' => 'tok-123', 'base_url' => 'https://sentry.example.test'],
        ]);

        return Integration::factory()->create([
            'team_id' => $team->id,
            'driver' => 'sentry',
            'credential_id' => $credential->id,
            'status' => IntegrationStatus::Active,
            'config' => array_merge(['org_slug' => 'acme'], $config),
        ]);
    }

    public function test_poll_scopes_to_a_single_project_when_project_id_is_set(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        (new SentryIntegrationDriver)->poll($this->makeIntegration(['project_id' => 6]));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/organizations/acme/issues/')
            && str_contains($request->url(), 'project=6'));
    }

    public function test_poll_is_org_wide_when_no_project_id(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        (new SentryIntegrationDriver)->poll($this->makeIntegration([]));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/organizations/acme/issues/')
            && ! str_contains($request->url(), 'project='));
    }

    public function test_poll_filters_to_unresolved_issues_only(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        (new SentryIntegrationDriver)->poll($this->makeIntegration([]));

        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'is:unresolved'));
    }

    public function test_repeated_polls_dedup_the_same_sentry_issue(): void
    {
        Queue::fake();

        $issue = ['id' => '90909', 'title' => 'TypeError in checkout', 'level' => 'error', 'count' => '3'];
        // Two polls of the same issue — second has a changed event count, the
        // kind of volatile field that defeats content_hash dedup.
        Http::fakeSequence()
            ->push([$issue], 200)
            ->push([array_merge($issue, ['count' => '11'])], 200);

        $this->makeIntegration([]);

        $this->artisan('integrations:poll', ['--driver' => 'sentry'])->assertExitCode(0);
        $this->artisan('integrations:poll', ['--driver' => 'sentry'])->assertExitCode(0);

        $signals = Signal::withoutGlobalScopes()->where('source_identifier', 'sentry')->get();
        $this->assertCount(1, $signals, 'The same Sentry issue dedups to one signal across polls.');
        $this->assertSame('sentry:90909', $signals->first()->source_native_id);
        $this->assertSame(1, $signals->first()->duplicate_count);
    }
}
