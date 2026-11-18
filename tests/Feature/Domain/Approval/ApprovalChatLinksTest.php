<?php

namespace Tests\Feature\Domain\Approval;

use App\Domain\Approval\Actions\CreateApprovalRequestAction;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Jobs\SendApprovalChatLinksJob;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Outbound\Models\OutboundConnectorConfig;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ApprovalChatLinksTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create(['settings' => []]);
        config([
            'services.slack.webhook_url' => null,
            'services.telegram.bot_token' => null,
        ]);
    }

    private function approval(array $overrides = []): ApprovalRequest
    {
        return ApprovalRequest::factory()->create(array_merge([
            'team_id' => $this->team->id,
            'experiment_id' => Experiment::factory()->create(['team_id' => $this->team->id])->id,
            'outbound_proposal_id' => null,
            'status' => ApprovalStatus::Pending,
            'context' => ['experiment_title' => 'Launch <b>Q4</b> & promo'],
        ], $overrides));
    }

    private function connector(string $channel, array $credentials, bool $active = true): void
    {
        OutboundConnectorConfig::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'channel' => $channel,
            'credentials' => $credentials,
            'is_active' => $active,
        ]);
    }

    public function test_job_dispatched_only_when_team_opts_in(): void
    {
        Queue::fake();
        $this->team->update(['settings' => ['approval_chat_links' => true]]);
        $experiment = Experiment::factory()->create(['team_id' => $this->team->id]);

        $approval = app(CreateApprovalRequestAction::class)->execute($experiment);

        Queue::assertPushed(SendApprovalChatLinksJob::class, fn ($job) => $job->approvalRequestId === $approval->id);
    }

    public function test_job_not_dispatched_when_setting_missing_or_false(): void
    {
        Queue::fake();
        $experiment = Experiment::factory()->create(['team_id' => $this->team->id]);
        app(CreateApprovalRequestAction::class)->execute($experiment);

        $this->team->update(['settings' => ['approval_chat_links' => false]]);
        app(CreateApprovalRequestAction::class)->execute($experiment);

        Queue::assertNotPushed(SendApprovalChatLinksJob::class);
    }

    public function test_slack_receives_block_kit_buttons_with_signed_links(): void
    {
        Http::fake();
        $this->connector('slack', ['webhook_url' => 'https://hooks.example.test/team-hook']);
        $approval = $this->approval();

        (new SendApprovalChatLinksJob($approval->id))->handle();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($approval) {
            if ($request->url() !== 'https://hooks.example.test/team-hook') {
                return false;
            }
            $actions = collect($request['blocks'])->firstWhere('type', 'actions');
            if (! $actions || count($actions['elements']) !== 2) {
                return false;
            }
            [$approve, $reject] = $actions['elements'];

            $section = collect($request['blocks'])->firstWhere('type', 'section');

            return $approve['style'] === 'primary'
                && $reject['style'] === 'danger'
                && str_contains($approve['url'], "/approvals/{$approval->id}/chat/approve")
                && str_contains($reject['url'], "/approvals/{$approval->id}/chat/reject")
                && str_contains($approve['url'], 'signature=')
                && str_contains($reject['url'], 'signature=')
                && str_contains($approve['url'], 'expires=')
                && str_contains($section['text']['text'], 'Launch &lt;b&gt;Q4&lt;/b&gt; &amp; promo');
        });
    }

    public function test_telegram_receives_inline_keyboard_with_signed_links(): void
    {
        Http::fake();
        $this->connector('telegram', ['bot_token' => '123:abc', 'chat_id' => '-10042']);
        $approval = $this->approval();

        (new SendApprovalChatLinksJob($approval->id))->handle();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($approval) {
            if ($request->url() !== 'https://api.telegram.org/bot123:abc/sendMessage') {
                return false;
            }
            $buttons = $request['reply_markup']['inline_keyboard'][0] ?? [];

            return $request['chat_id'] === '-10042'
                && $request['parse_mode'] === 'HTML'
                && count($buttons) === 2
                && str_contains($buttons[0]['url'], "/approvals/{$approval->id}/chat/approve")
                && str_contains($buttons[1]['url'], "/approvals/{$approval->id}/chat/reject")
                && str_contains($buttons[0]['url'], 'signature=')
                && str_contains($request['text'], 'Launch &lt;b&gt;Q4&lt;/b&gt; &amp; promo')
                && ! str_contains($request['text'], '<b>Q4</b>');
        });
    }

    public function test_nothing_sent_without_team_config(): void
    {
        Http::fake();
        config([
            'services.slack.webhook_url' => 'https://hooks.example.test/global',
            'services.telegram.bot_token' => '999:global',
        ]);
        $approval = $this->approval();

        (new SendApprovalChatLinksJob($approval->id))->handle();

        Http::assertNothingSent();
    }

    public function test_nothing_sent_when_config_inactive(): void
    {
        Http::fake();
        $this->connector('slack', ['webhook_url' => 'https://hooks.example.test/team-hook'], active: false);
        $this->connector('telegram', ['bot_token' => '123:abc', 'chat_id' => '-10042'], active: false);
        $approval = $this->approval();

        (new SendApprovalChatLinksJob($approval->id))->handle();

        Http::assertNothingSent();
    }

    public function test_nothing_sent_when_approval_not_pending(): void
    {
        Http::fake();
        $this->connector('slack', ['webhook_url' => 'https://hooks.example.test/team-hook']);
        $approval = $this->approval(['status' => ApprovalStatus::Approved]);

        (new SendApprovalChatLinksJob($approval->id))->handle();

        Http::assertNothingSent();
    }

    public function test_other_teams_config_is_not_used(): void
    {
        Http::fake();
        $other = Team::factory()->create();
        OutboundConnectorConfig::withoutGlobalScopes()->create([
            'team_id' => $other->id,
            'channel' => 'slack',
            'credentials' => ['webhook_url' => 'https://hooks.example.test/other'],
            'is_active' => true,
        ]);
        $approval = $this->approval();

        (new SendApprovalChatLinksJob($approval->id))->handle();

        Http::assertNothingSent();
    }

    public function test_slack_failure_does_not_stop_telegram(): void
    {
        Http::fake([
            'hooks.example.test/*' => Http::response('boom', 500),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
        $this->connector('slack', ['webhook_url' => 'https://hooks.example.test/team-hook']);
        $this->connector('telegram', ['bot_token' => '123:abc', 'chat_id' => '-10042']);
        $approval = $this->approval();

        (new SendApprovalChatLinksJob($approval->id))->handle();

        Http::assertSentCount(2);
    }
}
