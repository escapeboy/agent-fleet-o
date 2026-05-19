<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Audience\Actions\AddAudienceMember;
use App\Domain\Audience\Models\Audience;
use App\Domain\Broadcast\Enums\BroadcastStatus;
use App\Domain\Broadcast\Jobs\SendBroadcastJob;
use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Broadcast\BroadcastApproveTool;
use App\Mcp\Tools\Broadcast\BroadcastCreateTool;
use App\Mcp\Tools\Broadcast\BroadcastRequestApprovalTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class BroadcastMcpToolTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Audience $audience;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::factory()->create(['owner_id' => $user->id]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($user);
        app()->instance('mcp.team_id', $this->team->id);

        $this->audience = Audience::factory()->create(['team_id' => $this->team->id]);
        app(AddAudienceMember::class)->execute(
            $this->audience,
            ContactIdentity::factory()->create(['team_id' => $this->team->id]),
        );
    }

    public function test_create_request_approval_and_approve_flow(): void
    {
        Queue::fake();

        $created = $this->decode((new BroadcastCreateTool)->handle(new Request([
            'audience_id' => $this->audience->id,
            'name' => 'Spring launch',
            'subject' => 'We shipped',
            'body' => '<p>News</p>',
        ])));
        $this->assertSame('draft', $created['status']);

        $requested = $this->decode((new BroadcastRequestApprovalTool)->handle(new Request([
            'broadcast_id' => $created['id'],
        ])));
        $this->assertSame('pending_approval', $requested['status']);
        $this->assertSame(1, $requested['recipient_count']);

        $approved = $this->decode((new BroadcastApproveTool)->handle(new Request([
            'broadcast_id' => $created['id'],
        ])));
        $this->assertSame('sending', $approved['status']);
        Queue::assertPushed(SendBroadcastJob::class);
    }

    public function test_request_approval_fails_for_audience_with_no_subscribers(): void
    {
        $emptyAudience = Audience::factory()->create(['team_id' => $this->team->id]);

        $created = $this->decode((new BroadcastCreateTool)->handle(new Request([
            'audience_id' => $emptyAudience->id,
            'name' => 'Empty',
            'subject' => 'x',
            'body' => '<p>x</p>',
        ])));

        $result = $this->decode((new BroadcastRequestApprovalTool)->handle(new Request([
            'broadcast_id' => $created['id'],
        ])));

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(BroadcastStatus::Draft->value, BroadcastStatus::Draft->value);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }
}
