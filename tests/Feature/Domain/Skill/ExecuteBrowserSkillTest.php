<?php

namespace Tests\Feature\Domain\Skill;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Actions\ExecuteBrowserSkillAction;
use App\Domain\Skill\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExecuteBrowserSkillTest extends TestCase
{
    use RefreshDatabase;

    private ExecuteBrowserSkillAction $action;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(ExecuteBrowserSkillAction::class);

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        // Enable browser skill for tests
        Config::set('browser.enabled', true);
        Config::set('browser.url', 'http://browserless:3000');
        Config::set('browser.token', null);
        Config::set('browser.timeout', 30);
    }

    private function makeSkill(array $config = []): Skill
    {
        return Skill::create([
            'team_id' => $this->team->id,
            'slug' => 'browser-skill-'.uniqid(),
            'name' => 'Browser Skill',
            'type' => 'connector',
            'status' => 'active',
            'configuration' => $config,
        ]);
    }

    public function test_scrape_action_returns_html_and_text(): void
    {
        Http::fake([
            'http://browserless:3000/content' => Http::response('<html><body><h1>Hello World</h1></body></html>', 200),
        ]);

        $skill = $this->makeSkill();

        $result = $this->action->execute(
            skill: $skill,
            input: ['action' => 'scrape', 'url' => 'https://example.com'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNotNull($result['output']);
        $this->assertEquals('scrape', $result['output']['action']);
        $this->assertEquals('https://example.com', $result['output']['url']);
        $this->assertArrayHasKey('content', $result['output']);
        $this->assertArrayHasKey('text', $result['output']);

        $execution = $result['execution'];
        $this->assertEquals('completed', $execution->status);
        $this->assertEquals(0, $execution->cost_credits);
    }

    public function test_screenshot_action_returns_base64_png(): void
    {
        Http::fake([
            'http://browserless:3000/screenshot' => Http::response('fake-png-bytes', 200),
        ]);

        $skill = $this->makeSkill();

        $result = $this->action->execute(
            skill: $skill,
            input: ['action' => 'screenshot', 'url' => 'https://example.com'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNotNull($result['output']);
        $this->assertEquals('screenshot', $result['output']['action']);
        $this->assertEquals('image/png', $result['output']['content_type']);
        $this->assertArrayHasKey('data', $result['output']);
        $this->assertEquals(base64_encode('fake-png-bytes'), $result['output']['data']);
    }

    public function test_pdf_action_returns_base64_pdf(): void
    {
        Http::fake([
            'http://browserless:3000/pdf' => Http::response('%PDF-fake-content', 200),
        ]);

        $skill = $this->makeSkill();

        $result = $this->action->execute(
            skill: $skill,
            input: ['action' => 'pdf', 'url' => 'https://example.com'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNotNull($result['output']);
        $this->assertEquals('pdf', $result['output']['action']);
        $this->assertEquals('application/pdf', $result['output']['content_type']);
    }

    public function test_ssrf_blocked_for_localhost(): void
    {
        $skill = $this->makeSkill();

        $result = $this->action->execute(
            skill: $skill,
            input: ['action' => 'scrape', 'url' => 'http://localhost/admin'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNull($result['output']);
        $execution = $result['execution'];
        $this->assertEquals('failed', $execution->status);
        $this->assertStringContainsString('not allowed', $execution->error_message);
    }

    public function test_ssrf_blocked_for_private_ip(): void
    {
        $skill = $this->makeSkill();

        $result = $this->action->execute(
            skill: $skill,
            input: ['action' => 'scrape', 'url' => 'http://192.168.1.1/secret'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNull($result['output']);
        $this->assertEquals('failed', $result['execution']->status);
    }

    public function test_ssrf_blocked_for_non_http_scheme(): void
    {
        $skill = $this->makeSkill();

        $result = $this->action->execute(
            skill: $skill,
            input: ['action' => 'scrape', 'url' => 'ftp://example.com/file'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNull($result['output']);
        $this->assertEquals('failed', $result['execution']->status);
    }

    public function test_missing_url_fails_gracefully(): void
    {
        $skill = $this->makeSkill();

        $result = $this->action->execute(
            skill: $skill,
            input: ['action' => 'scrape'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNull($result['output']);
        $this->assertEquals('failed', $result['execution']->status);
        $this->assertStringContainsString('Missing required input', $result['execution']->error_message);
    }

    public function test_disabled_browser_skill_returns_failed_execution(): void
    {
        Config::set('browser.enabled', false);

        $skill = $this->makeSkill();

        $result = $this->action->execute(
            skill: $skill,
            input: ['action' => 'scrape', 'url' => 'https://example.com'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNull($result['output']);
        $this->assertEquals('failed', $result['execution']->status);
        $this->assertStringContainsString('not enabled', $result['execution']->error_message);
    }

    public function test_token_auth_header_is_sent_when_configured(): void
    {
        Config::set('browser.token', 'my-browser-token');

        Http::fake([
            'http://browserless:3000/content' => Http::response('<html></html>', 200),
        ]);

        $skill = $this->makeSkill();

        $this->action->execute(
            skill: $skill,
            input: ['action' => 'scrape', 'url' => 'https://example.com'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization')
                && $request->header('Authorization')[0] === 'Token my-browser-token';
        });
    }

    public function test_execution_is_recorded_in_skill_executions_table(): void
    {
        Http::fake([
            'http://browserless:3000/content' => Http::response('<html></html>', 200),
        ]);

        $skill = $this->makeSkill();

        $this->action->execute(
            skill: $skill,
            input: ['action' => 'scrape', 'url' => 'https://example.com'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertDatabaseHas('skill_executions', [
            'skill_id' => $skill->id,
            'team_id' => $this->team->id,
            'status' => 'completed',
            'cost_credits' => 0,
        ]);
    }
}
