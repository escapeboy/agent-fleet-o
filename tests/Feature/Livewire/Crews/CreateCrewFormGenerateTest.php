<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Crews;

use App\Domain\Crew\Actions\GenerateCrewFromPromptAction;
use App\Domain\Shared\Exceptions\AiAccessUnavailableException;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\FormatGuidePromptInjector;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Livewire\Crews\CreateCrewForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

class CreateCrewFormGenerateTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamUser(): void
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'Crew Gen Team',
            'slug' => 'crew-gen-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $team->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user);
    }

    private function bindActionWithGatewayError(\Throwable $error): void
    {
        $gateway = $this->createStub(AiGatewayInterface::class);
        $gateway->method('complete')->willThrowException($error);

        $resolver = $this->createStub(ProviderResolver::class);
        $resolver->method('resolve')->willReturn(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5-20251001']);

        $this->app->instance(GenerateCrewFromPromptAction::class, new GenerateCrewFromPromptAction(
            $gateway,
            $resolver,
            app(FormatGuidePromptInjector::class),
        ));
    }

    public function test_no_ai_access_shows_message_and_does_not_log_error(): void
    {
        $this->actingTeamUser();
        $this->bindActionWithGatewayError(AiAccessUnavailableException::forTeam());

        $levels = [];
        Log::listen(function ($message) use (&$levels): void {
            $levels[] = $message->level;
        });

        Livewire::test(CreateCrewForm::class)
            ->set('generatePrompt', 'Build a content research and writing crew')
            ->call('generateFromPrompt')
            ->assertHasErrors(['generatePrompt'])
            ->assertSet('generating', false);

        $this->assertNotContains('error', $levels);
    }

    public function test_other_failures_still_propagate(): void
    {
        $this->actingTeamUser();
        $this->bindActionWithGatewayError(new \RuntimeException('provider exploded'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('provider exploded');

        Livewire::test(CreateCrewForm::class)
            ->set('generatePrompt', 'Build a content research and writing crew')
            ->call('generateFromPrompt');
    }
}
