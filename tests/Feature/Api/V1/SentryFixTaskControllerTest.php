<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Enums\FixTier;
use App\Domain\Signal\Enums\SentryFixTaskState;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Models\SentryFixTask;
use App\Domain\Signal\Models\Signal;
use App\Infrastructure\Auth\SanctumTokenIssuer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class SentryFixTaskControllerTest extends ApiTestCase
{
    private int $seq = 0;

    private function task(array $overrides = [], ?Team $team = null): SentryFixTask
    {
        $this->seq++;

        return SentryFixTask::withoutGlobalScopes()->create(array_merge([
            'team_id' => ($team ?? $this->team)->id,
            'sentry_issue_id' => 'issue-'.$this->seq,
            'project' => 'shop-backend',
            'repository' => 'acme/shop',
            'title' => 'Issue '.$this->seq,
            'root_cause' => 'cause',
            'suspect_files' => ['app/Foo.php'],
            'confidence' => 0.9,
            'is_critical' => false,
            'state' => SentryFixTaskState::Queued,
            'attempts' => 0,
            'events' => [],
            'created_at' => now()->subMinutes(100 - $this->seq),
        ], $overrides));
    }

    /** Real personal access token (the guard rejects mocked/transient ones). */
    protected function actingAsApiUser(array $abilities = ['*'], ?User $user = null): static
    {
        $this->withToken(SanctumTokenIssuer::create($user ?? $this->user, 't', $abilities)->plainTextToken);

        return $this;
    }

    private function otherTeam(): Team
    {
        $owner = User::factory()->create();

        return Team::create(['name' => 'Other', 'slug' => 'other-'.Str::random(5), 'owner_id' => $owner->id, 'settings' => []]);
    }

    // F4
    public function test_claim_returns_oldest_queued_and_marks_it_claimed(): void
    {
        $oldest = $this->task();
        $this->task();
        $this->actingAsApiUser(['sentry-fixes']);

        $response = $this->postJson('/api/v1/sentry-fix-tasks/claim', ['worker' => 'fixer-1']);

        $response->assertOk()
            ->assertJsonPath('data.id', $oldest->id)
            ->assertJsonPath('data.state', 'claimed')
            ->assertJsonPath('data.claimed_by', 'fixer-1')
            ->assertJsonPath('data.attempts', 1);
        $this->assertNotNull($response->json('data.lease_expires_at'));
        $this->assertEqualsWithDelta(
            now()->addHours(3)->timestamp,
            Carbon::parse($response->json('data.lease_expires_at'))->timestamp,
            60,
        );
        $this->assertSame('claimed', $response->json('data.events.0.state'));
    }

    public function test_claim_prefers_critical_tasks(): void
    {
        $this->task();
        $critical = $this->task(['is_critical' => true]);
        $this->actingAsApiUser(['sentry-fixes']);

        $this->postJson('/api/v1/sentry-fix-tasks/claim', ['worker' => 'w'])
            ->assertOk()
            ->assertJsonPath('data.id', $critical->id);
    }

    public function test_second_claim_gets_next_task_then_204(): void
    {
        $first = $this->task();
        $second = $this->task();
        $this->actingAsApiUser(['sentry-fixes']);

        $this->postJson('/api/v1/sentry-fix-tasks/claim', ['worker' => 'a'])->assertJsonPath('data.id', $first->id);
        $this->postJson('/api/v1/sentry-fix-tasks/claim', ['worker' => 'b'])->assertJsonPath('data.id', $second->id);
        $this->postJson('/api/v1/sentry-fix-tasks/claim', ['worker' => 'c'])->assertNoContent();
    }

    public function test_claim_skips_excluded_repositories(): void
    {
        $this->task(['repository' => 'acme/excluded']);
        $kept = $this->task(['repository' => 'acme/other']);
        $this->actingAsApiUser(['sentry-fixes']);

        $this->postJson('/api/v1/sentry-fix-tasks/claim', [
            'worker' => 'w',
            'exclude_repositories' => ['acme/excluded'],
        ])->assertOk()->assertJsonPath('data.id', $kept->id);

        $this->postJson('/api/v1/sentry-fix-tasks/claim', [
            'worker' => 'w',
            'exclude_repositories' => ['acme/excluded'],
        ])->assertNoContent();
    }

    public function test_claim_ignores_other_teams_and_non_queued_tasks(): void
    {
        $this->task(['state' => SentryFixTaskState::Fixing]);
        $this->task([], $this->otherTeam());
        $this->actingAsApiUser(['sentry-fixes']);

        $this->postJson('/api/v1/sentry-fix-tasks/claim', ['worker' => 'w'])->assertNoContent();
    }

    public function test_claim_requires_worker(): void
    {
        $this->actingAsApiUser(['sentry-fixes']);

        $this->postJson('/api/v1/sentry-fix-tasks/claim', [])->assertStatus(422);
    }

    public function test_index_filters_by_state_and_show_returns_task(): void
    {
        $approved = $this->task(['state' => SentryFixTaskState::Approved]);
        $this->task();
        $this->actingAsApiUser(['sentry-fixes']);

        $this->getJson('/api/v1/sentry-fix-tasks?state=approved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $approved->id);

        $this->getJson('/api/v1/sentry-fix-tasks?state=approved,queued')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/sentry-fix-tasks?state=bogus')->assertStatus(422);

        $this->getJson("/api/v1/sentry-fix-tasks/{$approved->id}")
            ->assertOk()
            ->assertJsonPath('data.repository', 'acme/shop')
            ->assertJsonPath('data.suspect_files.0', 'app/Foo.php');
    }

    // F5
    public function test_allowed_event_updates_state_appends_trail_and_extends_lease(): void
    {
        $task = $this->task(['state' => SentryFixTaskState::Claimed, 'lease_expires_at' => now()->addMinute()]);
        $this->actingAsApiUser(['sentry-fixes']);

        $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/events", [
            'state' => 'pr_open',
            'message' => 'PR opened',
            'pr_url' => 'https://github.com/acme/shop/pull/7',
        ])->assertOk()
            ->assertJsonPath('data.state', 'pr_open')
            ->assertJsonPath('data.pr_url', 'https://github.com/acme/shop/pull/7');

        $task->refresh();
        $this->assertSame(SentryFixTaskState::PrOpen, $task->state);
        $this->assertSame('PR opened', $task->events[0]['message']);
        $this->assertSame('pr_open', $task->events[0]['state']);
        $this->assertTrue($task->lease_expires_at->gt(now()->addHours(2)));
    }

    public function test_awaiting_approval_clears_the_lease(): void
    {
        $task = $this->task(['state' => SentryFixTaskState::PrOpen, 'lease_expires_at' => now()->addHour()]);
        $this->actingAsApiUser(['sentry-fixes']);

        $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/events", ['state' => 'awaiting_approval', 'message' => 'sent'])->assertOk();

        $this->assertNull($task->refresh()->lease_expires_at);
    }

    public function test_disallowed_transition_returns_422_and_changes_nothing(): void
    {
        $task = $this->task();
        $this->actingAsApiUser(['sentry-fixes']);

        $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/events", ['state' => 'done', 'message' => 'x'])
            ->assertStatus(422);

        $task->refresh();
        $this->assertSame(SentryFixTaskState::Queued, $task->state);
        $this->assertSame([], $task->events);
    }

    public function test_terminal_task_accepts_no_further_events(): void
    {
        $task = $this->task(['state' => SentryFixTaskState::Done]);
        $this->actingAsApiUser(['sentry-fixes']);

        $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/events", ['state' => 'done', 'message' => 'x'])->assertStatus(422);
        $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/events", ['state' => 'queued', 'message' => 'x'])->assertStatus(422);
    }

    public function test_unknown_state_is_a_validation_error(): void
    {
        $task = $this->task();
        $this->actingAsApiUser(['sentry-fixes']);

        $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/events", ['state' => 'bogus', 'message' => 'x'])->assertStatus(422);
    }

    public function test_done_resolves_the_signal(): void
    {
        $signal = Signal::create([
            'team_id' => $this->team->id,
            'source_type' => 'integration',
            'source_identifier' => 'sentry',
            'payload' => ['title' => 'x'],
            'content_hash' => hash('sha256', 'done-signal'),
            'received_at' => now(),
            'status' => SignalStatus::DelegatedToAgent,
        ]);
        $task = $this->task(['state' => SentryFixTaskState::Watching, 'signal_id' => $signal->id]);
        $this->actingAsApiUser(['sentry-fixes']);

        $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/events", ['state' => 'done', 'message' => 'healthy'])
            ->assertOk()->assertJsonPath('data.state', 'done');

        $this->assertSame(SignalStatus::Resolved, $signal->refresh()->status);
        $this->assertNull($task->refresh()->lease_expires_at);
    }

    public function test_reverted_does_not_resolve_the_signal(): void
    {
        $signal = Signal::create([
            'team_id' => $this->team->id,
            'source_type' => 'integration',
            'source_identifier' => 'sentry',
            'payload' => ['title' => 'x'],
            'content_hash' => hash('sha256', 'rev-signal'),
            'received_at' => now(),
            'status' => SignalStatus::DelegatedToAgent,
        ]);
        $task = $this->task(['state' => SentryFixTaskState::Watching, 'signal_id' => $signal->id]);
        $this->actingAsApiUser(['sentry-fixes']);

        $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/events", ['state' => 'reverted', 'message' => 'regression'])->assertOk();

        $this->assertSame(SignalStatus::DelegatedToAgent, $signal->refresh()->status);
    }

    // F6
    public function test_classify_returns_and_stores_the_classifier_tier(): void
    {
        $task = $this->task(['state' => SentryFixTaskState::Fixing]);
        $this->actingAsApiUser(['sentry-fixes']);

        $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/classify", ['files' => ['app/Foo.php'], 'diff_lines' => 10])
            ->assertOk()->assertJsonPath('data.tier', 't1');
        $this->assertSame(FixTier::T1, $task->refresh()->tier);

        $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/classify", ['files' => ['database/migrations/2026_x.php'], 'diff_lines' => 10])
            ->assertJsonPath('data.tier', 't4');
        $this->assertSame(FixTier::T4, $task->refresh()->tier);

        $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/classify", ['files' => ['a.php', 'b.php', 'c.php', 'd.php'], 'diff_lines' => 100])
            ->assertJsonPath('data.tier', 't2');
    }

    public function test_classify_validates_input(): void
    {
        $task = $this->task();
        $this->actingAsApiUser(['sentry-fixes']);

        $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/classify", ['files' => ['a.php']])->assertStatus(422);
        $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/classify", ['files' => [], 'diff_lines' => 5])
            ->assertOk()->assertJsonPath('data.tier', 't4');
        $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/classify", ['diff_lines' => 3])->assertStatus(422);
    }

    // F7
    public function test_unauthenticated_requests_get_401(): void
    {
        $task = $this->task();

        $this->postJson('/api/v1/sentry-fix-tasks/claim', ['worker' => 'w'])->assertStatus(401);
        $this->getJson('/api/v1/sentry-fix-tasks')->assertStatus(401);
        $this->getJson("/api/v1/sentry-fix-tasks/{$task->id}")->assertStatus(401);
        $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/events", ['state' => 'claimed', 'message' => 'x'])->assertStatus(401);
        $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/classify", ['files' => [], 'diff_lines' => 1])->assertStatus(401);
    }

    public function test_token_without_the_ability_gets_403_on_every_endpoint(): void
    {
        $task = $this->task();
        $this->actingAsApiUser(['team:'.$this->team->id]);

        $this->postJson('/api/v1/sentry-fix-tasks/claim', ['worker' => 'w'])->assertForbidden();
        $this->getJson('/api/v1/sentry-fix-tasks')->assertForbidden();
        $this->getJson("/api/v1/sentry-fix-tasks/{$task->id}")->assertForbidden();
        $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/events", ['state' => 'claimed', 'message' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/classify", ['files' => [], 'diff_lines' => 1])->assertForbidden();
        $this->assertSame(SentryFixTaskState::Queued, $task->refresh()->state);
    }

    public function test_other_teams_task_is_404(): void
    {
        $foreign = $this->task([], $this->otherTeam());
        $this->actingAsApiUser(['sentry-fixes']);

        $this->getJson("/api/v1/sentry-fix-tasks/{$foreign->id}")->assertNotFound();
        $this->postJson("/api/v1/sentry-fix-tasks/{$foreign->id}/events", ['state' => 'claimed', 'message' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/sentry-fix-tasks/{$foreign->id}/classify", ['files' => [], 'diff_lines' => 1])->assertNotFound();
        $this->getJson('/api/v1/sentry-fix-tasks')->assertJsonCount(0, 'data');
        $this->assertContains($this->getJson('/api/v1/sentry-fix-tasks/not-a-uuid')->getStatusCode(), [403, 404]);
        $this->assertSame(SentryFixTaskState::Queued, $foreign->refresh()->state);
    }

    public function test_issued_api_token_can_carry_the_sentry_fixes_ability(): void
    {
        $this->actingAsApiUser();

        $this->postJson('/api/v1/team/tokens', [
            'name' => 'fix worker',
            'abilities' => ['team:'.$this->team->id, 'sentry-fixes'],
        ])->assertCreated();

        $token = PersonalAccessToken::where('name', 'fix worker')->sole();
        $this->assertEqualsCanonicalizing(['team:'.$this->team->id, 'sentry-fixes'], $token->abilities);

        $this->postJson('/api/v1/team/tokens', ['name' => 'bad', 'abilities' => ['*']])->assertStatus(422);
        $this->postJson('/api/v1/team/tokens', ['name' => 'bad2', 'abilities' => ['team:'.$this->team->id, 'admin']])->assertStatus(422);
    }

    public function test_wildcard_token_is_rejected(): void
    {
        $this->actingAsApiUser(['*']);

        $this->postJson('/api/v1/sentry-fix-tasks/claim', ['worker' => 'w'])->assertForbidden();
    }

    public function test_cookie_session_user_without_a_personal_access_token_is_rejected(): void
    {
        $this->task();
        $this->actingAs($this->user, 'web');

        $this->getJson('/api/v1/sentry-fix-tasks')->assertForbidden();
    }

    public function test_viewer_token_is_rejected_even_with_the_ability(): void
    {
        $viewer = $this->createTeamMember('viewer');
        $this->actingAsApiUser(['sentry-fixes'], $viewer);

        $this->getJson('/api/v1/sentry-fix-tasks')->assertForbidden();
    }

    public function test_token_team_wins_over_current_team_id(): void
    {
        $other = $this->otherTeam();
        $other->users()->attach($this->user, ['role' => 'owner']);
        $mine = $this->task();
        $theirs = $this->task([], $other);
        $this->user->update(['current_team_id' => $other->id]);
        $this->actingAsApiUser(['team:'.$this->team->id, 'sentry-fixes']);

        $this->getJson('/api/v1/sentry-fix-tasks')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->getJson("/api/v1/sentry-fix-tasks/{$mine->id}")->assertOk();
        $this->getJson("/api/v1/sentry-fix-tasks/{$theirs->id}")->assertNotFound();
        $this->postJson("/api/v1/sentry-fix-tasks/{$mine->id}/classify", ['files' => [], 'diff_lines' => 1])->assertOk();
        $this->postJson('/api/v1/sentry-fix-tasks/claim', ['worker' => 'w'])->assertOk()->assertJsonPath('data.id', $mine->id);
    }

    public function test_token_for_a_team_the_user_left_is_rejected(): void
    {
        $other = $this->otherTeam();
        $this->actingAsApiUser(['team:'.$other->id, 'sentry-fixes']);

        $this->getJson('/api/v1/sentry-fix-tasks')->assertForbidden();
    }

    public function test_viewer_and_member_cannot_mint_a_sentry_fixes_token(): void
    {
        foreach (['viewer', 'member'] as $role) {
            $user = $this->createTeamMember($role);
            $this->actingAsApiUser(['*'], $user);

            $this->postJson('/api/v1/team/tokens', ['name' => "t-$role", 'abilities' => ['team:'.$this->team->id, 'sentry-fixes']])->assertForbidden();
            $this->postJson('/api/v1/team/tokens', ['name' => "ok-$role"])->assertCreated();
        }
        $this->assertSame(0, PersonalAccessToken::where('name', 'like', 't-%')->count());
    }

    public function test_admin_can_mint_a_sentry_fixes_token(): void
    {
        $admin = $this->createTeamMember('admin');
        $this->actingAsApiUser(['*'], $admin);

        $this->postJson('/api/v1/team/tokens', ['name' => 'admin-fix', 'abilities' => ['team:'.$this->team->id, 'sentry-fixes']])->assertCreated();
    }

    public function test_classify_refuses_terminal_tasks(): void
    {
        $task = $this->task(['state' => SentryFixTaskState::Done]);
        $this->actingAsApiUser(['sentry-fixes']);

        $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/classify", ['files' => [], 'diff_lines' => 1])->assertStatus(422);
        $this->assertNull($task->refresh()->tier);
    }

    public function test_pr_url_must_be_https(): void
    {
        $task = $this->task(['state' => SentryFixTaskState::Fixing]);
        $this->actingAsApiUser(['sentry-fixes']);

        foreach (['javascript:alert(1)', 'http://example.com/pr/1', 'not a url'] as $bad) {
            $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/events", ['state' => 'pr_open', 'message' => 'x', 'pr_url' => $bad])->assertStatus(422);
        }
        $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/events", ['state' => 'pr_open', 'message' => 'x', 'pr_url' => 'https://example.com/pr/1'])
            ->assertOk()->assertJsonPath('data.pr_url', 'https://example.com/pr/1');
    }

    public function test_done_leaves_an_already_resolved_signal_and_works_without_a_signal(): void
    {
        $signal = Signal::create([
            'team_id' => $this->team->id,
            'source_type' => 'integration',
            'source_identifier' => 'sentry',
            'payload' => ['title' => 'x'],
            'content_hash' => hash('sha256', 'resolved-signal'),
            'received_at' => now(),
            'status' => SignalStatus::Resolved,
        ]);
        $withSignal = $this->task(['state' => SentryFixTaskState::Watching, 'signal_id' => $signal->id]);
        $without = $this->task(['state' => SentryFixTaskState::Watching]);
        $this->actingAsApiUser(['sentry-fixes']);

        $this->postJson("/api/v1/sentry-fix-tasks/{$withSignal->id}/events", ['state' => 'done', 'message' => 'ok'])->assertOk();
        $this->postJson("/api/v1/sentry-fix-tasks/{$without->id}/events", ['state' => 'done', 'message' => 'ok'])->assertOk();

        $this->assertSame(SignalStatus::Resolved, $signal->refresh()->status);
    }

    public function test_done_resolves_a_signal_whose_status_has_no_direct_transition(): void
    {
        $signal = Signal::create([
            'team_id' => $this->team->id,
            'source_type' => 'integration',
            'source_identifier' => 'sentry',
            'payload' => ['title' => 'x'],
            'content_hash' => hash('sha256', 'received-signal'),
            'received_at' => now(),
            'status' => SignalStatus::Received,
        ]);
        $task = $this->task(['state' => SentryFixTaskState::Watching, 'signal_id' => $signal->id]);
        $this->actingAsApiUser(['sentry-fixes']);

        $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/events", ['state' => 'done', 'message' => 'ok'])->assertOk();

        $this->assertSame(SignalStatus::Resolved, $signal->refresh()->status);
    }

    public function test_sentry_fixes_token_can_only_reach_the_fix_task_routes(): void
    {
        $task = $this->task();
        $token = SanctumTokenIssuer::create($this->user, 'fix', ['team:'.$this->team->id, 'sentry-fixes'])->plainTextToken;

        $this->app['auth']->forgetGuards();
        $this->assertContains($this->withToken($token)->postJson('/api/v1/team/tokens', ['name' => 'more', 'abilities' => ['team:'.$this->team->id, 'sentry-fixes']])->getStatusCode(), [401, 403]);
        $this->app['auth']->forgetGuards();
        $this->assertContains($this->withToken($token)->getJson('/api/v1/team/tokens')->getStatusCode(), [401, 403]);
        $this->app['auth']->forgetGuards();
        $this->assertContains($this->withToken($token)->putJson('/api/v1/team', ['name' => 'Hijacked'])->getStatusCode(), [401, 403]);
        $this->app['auth']->forgetGuards();
        $this->assertContains($this->withToken($token)->getJson('/api/v1/team')->getStatusCode(), [401, 403]);
        $this->assertSame(0, PersonalAccessToken::where('name', 'more')->count());
        $this->assertSame('Test Team', $this->team->refresh()->name);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/sentry-fix-tasks')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson("/api/v1/sentry-fix-tasks/{$task->id}")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/sentry-fix-tasks/claim', ['worker' => 'w'])->assertOk();
    }

    public function test_tokens_without_the_ability_are_not_restricted_by_the_fix_token_rule(): void
    {
        $this->actingAsApiUser(['*']);

        $this->getJson('/api/v1/team')->assertOk();
    }

    public function test_sentry_fixes_token_gets_an_expiry_and_other_tokens_do_not(): void
    {
        config(['sentry_watchdog.fix_token_ttl_days' => 30]);
        $this->actingAsApiUser(['*']);

        $this->postJson('/api/v1/team/tokens', ['name' => 'fix', 'abilities' => ['team:'.$this->team->id, 'sentry-fixes']])->assertCreated();
        $this->postJson('/api/v1/team/tokens', ['name' => 'plain'])->assertCreated();

        $fix = PersonalAccessToken::where('name', 'fix')->sole();
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $fix->expires_at->timestamp, 60);
        $this->assertNull(PersonalAccessToken::where('name', 'plain')->sole()->expires_at);
    }

    public function test_events_for_claim_phases_must_come_from_the_claiming_worker(): void
    {
        $task = $this->task(['state' => SentryFixTaskState::Claimed, 'claimed_by' => 'fixer-1', 'attempts' => 1]);
        $this->actingAsApiUser(['sentry-fixes']);

        $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/events", ['state' => 'fixing', 'message' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/events", ['state' => 'fixing', 'message' => 'x', 'worker' => 'other'])->assertForbidden();
        $this->assertSame(SentryFixTaskState::Claimed, $task->refresh()->state);

        $this->postJson("/api/v1/sentry-fix-tasks/{$task->id}/events", ['state' => 'fixing', 'message' => 'x', 'worker' => 'fixer-1'])
            ->assertOk()->assertJsonPath('data.state', 'fixing');
    }

    public function test_sentry_fixes_token_does_not_authenticate_on_mcp_or_other_route_groups(): void
    {
        // The MCP routes fall through to the passport guard, which needs OAuth keys (absent in CI).
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privatePem);
        config(['passport.private_key' => $privatePem, 'passport.public_key' => openssl_pkey_get_details($key)['key']]);

        Route::middleware('auth:sanctum')->get('/cloudlike/ping', fn () => response()->json(['ok' => true]));
        $fix = SanctumTokenIssuer::create($this->user, 'fix', ['team:'.$this->team->id, 'sentry-fixes'])->plainTextToken;
        $plain = SanctumTokenIssuer::create($this->user, 'plain', ['*'])->plainTextToken;
        $mint = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'api_token_manage', 'arguments' => ['action' => 'create', 'name' => 'escalated']]];
        $list = ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []];

        $this->app['auth']->forgetGuards();
        $this->withToken($fix)->postJson('/mcp/full', $mint)->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($fix)->postJson('/mcp', $list)->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($fix)->getJson('/cloudlike/ping')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($fix)->getJson('/api/v1/sentry-fix-tasks/../team')->assertStatus(404);
        $this->assertSame(0, PersonalAccessToken::where('name', 'escalated')->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/cloudlike/ping')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($fix)->getJson('/api/v1/sentry-fix-tasks')->assertOk();
    }

    public function test_sentry_fixes_token_is_rejected_on_lookalike_paths(): void
    {
        $fix = SanctumTokenIssuer::create($this->user, 'fix', ['team:'.$this->team->id, 'sentry-fixes'])->plainTextToken;

        foreach (['/api/v1/sentry-fix-tasks-evil', '/api/v1/sentry-fix-tasks/x/y', '/api/v1/team', '/api/v1/me'] as $uri) {
            $this->app['auth']->forgetGuards();
            $this->assertContains($this->withToken($fix)->getJson($uri)->getStatusCode(), [401, 403, 404], $uri);
        }
    }
}
