<?php

namespace Tests\Feature\Domain\Telegram;

use App\Domain\Shared\Models\Team;
use App\Domain\Telegram\Actions\RegisterTelegramBotAction;
use App\Domain\Telegram\Models\TelegramBot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RegisterTelegramBotTest extends TestCase
{
    use RefreshDatabase;

    private RegisterTelegramBotAction $action;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(RegisterTelegramBotAction::class);

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    private function fakeSuccessfulGetMe(string $username = 'testbot', string $firstName = 'Test Bot'): void
    {
        Http::fake([
            'https://api.telegram.org/bot*/getMe' => Http::response([
                'ok' => true,
                'result' => [
                    'id' => 123456789,
                    'is_bot' => true,
                    'first_name' => $firstName,
                    'username' => $username,
                ],
            ], 200),
        ]);
    }

    public function test_registers_bot_with_valid_token(): void
    {
        $this->fakeSuccessfulGetMe('mybot', 'My Bot');

        $bot = $this->action->execute(
            teamId: $this->team->id,
            botToken: 'valid-token-123',
        );

        $this->assertInstanceOf(TelegramBot::class, $bot);
        $this->assertEquals($this->team->id, $bot->team_id);
        $this->assertEquals('mybot', $bot->bot_username);
        $this->assertEquals('My Bot', $bot->bot_name);
        $this->assertEquals('active', $bot->status);
        $this->assertEquals('assistant', $bot->routing_mode);
    }

    public function test_updates_existing_bot_for_same_team(): void
    {
        // Pre-create a bot for this team
        TelegramBot::create([
            'team_id' => $this->team->id,
            'bot_token' => 'old-token',
            'bot_username' => 'firstbot',
            'bot_name' => 'First Bot',
            'routing_mode' => 'assistant',
            'status' => 'active',
        ]);

        // Now register again with a new token — should update, not create
        $this->fakeSuccessfulGetMe('secondbot', 'Second Bot');
        $this->action->execute($this->team->id, 'new-token-456');

        // Only one bot per team
        $this->assertEquals(1, TelegramBot::withoutGlobalScopes()->where('team_id', $this->team->id)->count());

        $bot = TelegramBot::withoutGlobalScopes()->where('team_id', $this->team->id)->first();
        $this->assertEquals('secondbot', $bot->bot_username);
    }

    public function test_throws_validation_exception_on_invalid_token(): void
    {
        Http::fake([
            'https://api.telegram.org/bot*/getMe' => Http::response([
                'ok' => false,
                'description' => 'Unauthorized',
            ], 401),
        ]);

        $this->expectException(ValidationException::class);

        $this->action->execute(
            teamId: $this->team->id,
            botToken: 'invalid-token',
        );
    }

    public function test_throws_validation_exception_on_connection_failure(): void
    {
        Http::fake([
            'https://api.telegram.org/bot*/getMe' => Http::response([], 500),
        ]);

        $this->expectException(ValidationException::class);

        $this->action->execute(
            teamId: $this->team->id,
            botToken: 'token-that-causes-server-error',
        );
    }

    public function test_routing_mode_is_stored_correctly(): void
    {
        $this->fakeSuccessfulGetMe();

        $bot = $this->action->execute(
            teamId: $this->team->id,
            botToken: 'token',
            routingMode: 'trigger_rules',
        );

        $this->assertEquals('trigger_rules', $bot->routing_mode);
    }
}
