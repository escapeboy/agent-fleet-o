<?php

namespace Tests\Feature\Domain\Shared;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Listeners\BroadcastExperimentTransitioned;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class BroadcastExperimentTransitionedResilienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_relay_outage_does_not_crash_the_transition_listener(): void
    {
        // TeamActivityBroadcast is ShouldBroadcastNow and is dispatched inside
        // the transition's DB transaction. A throwing relay must be swallowed so
        // it cannot roll back the experiment transition (Sentry #939/#941/#944).
        Broadcast::extend('boom', fn () => new class extends Broadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = [])
            {
                throw new \RuntimeException('Pusher error: No matching application for ID [fleetq-bridge].');
            }
        });
        config(['broadcasting.default' => 'boom']);

        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'Broadcast Resilience Test',
            'slug' => 'broadcast-resilience-test',
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        $experiment = Experiment::create([
            'team_id' => $team->id,
            'title' => 'Resilience Target',
            'status' => 'completed',
            'track' => 'growth',
            'description' => 't',
            'user_id' => $user->id,
            'initiated_by_user_id' => $user->id,
        ]);

        Log::spy();

        (new BroadcastExperimentTransitioned)->handle(new ExperimentTransitioned(
            experiment: $experiment,
            fromState: ExperimentStatus::Evaluating,
            toState: ExperimentStatus::Completed,
        ));

        // No exception escaped; the failure was logged as a warning.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'TeamActivityBroadcast failed'))
            ->once();
    }
}
