<?php

namespace Tests\Feature\Mcp;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\Auth\SanctumTokenIssuer;
use App\Infrastructure\Auth\ScopedPersonalAccessToken;
use App\Infrastructure\Auth\ScopedTransientToken;
use App\Mcp\Exceptions\InputRequiredException;
use App\Mcp\Methods\MultiRoundTripCallTool;
use App\Mcp\Protocol\ProtocolContext;
use App\Mcp\Protocol\ProtocolVersions;
use App\Mcp\Services\McpToolCallAuditor;
use App\Mcp\Tools\Compact\CompactTool;
use App\Models\User;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Schema\Implementation;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Tests\TestCase;

class McpToolCallAuditTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T '.bin2hex(random_bytes(3)),
            'slug' => 't-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);

        app()->instance('mcp.team_id', $this->team->id);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(ProtocolContext::BINDING);
        app()->forgetInstance('mcp.team_id');

        parent::tearDown();
    }

    private function context(): ServerContext
    {
        return new ServerContext(
            supportedProtocolVersions: ProtocolVersions::SUPPORTED,
            serverCapabilities: [],
            implementation: new Implementation(name: 'test', version: '1.0'),
            instructions: '',
            maxPaginationLength: 100,
            defaultPaginationLength: 50,
            tools: [
                AuditFixtureWriteTool::class,
                AuditFixtureReadTool::class,
                AuditFixtureStructuredErrorTool::class,
                AuditFixturePlainErrorTool::class,
                AuditFixtureCompactTool::class,
                AuditFixtureGatedTool::class,
                AuditFixtureStreamTool::class,
            ],
            resources: [],
            prompts: [],
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callTool(string $name, array $arguments = []): array
    {
        $request = new JsonRpcRequest(
            id: 1,
            method: 'tools/call',
            params: ['name' => $name, 'arguments' => $arguments],
        );

        app()->instance('mcp.request', $request->toRequest());

        try {
            $response = app(MultiRoundTripCallTool::class)->handle($request, $this->context());
        } finally {
            app()->forgetInstance('mcp.request');
        }

        return $response->toArray()['result'] ?? [];
    }

    private function onlyEntry(): AuditEntry
    {
        $entries = AuditEntry::withoutGlobalScopes()->where('event', McpToolCallAuditor::EVENT)->get();
        $this->assertCount(1, $entries);

        $entry = $entries->first();
        $this->assertInstanceOf(AuditEntry::class, $entry);

        return $entry;
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyEntryProperties(): array
    {
        $props = $this->onlyEntry()->getAttribute('properties');
        $this->assertIsArray($props);

        return $props;
    }

    private function auditCount(): int
    {
        return AuditEntry::withoutGlobalScopes()->where('event', McpToolCallAuditor::EVENT)->count();
    }

    public function test_write_tool_call_with_sanctum_token_is_audited(): void
    {
        $issued = SanctumTokenIssuer::create($this->user, 'my-pat');

        // Mirror CompatibleSanctumGuard: it attaches the resolved token model to the user.
        $token = ScopedPersonalAccessToken::findOrFail($issued->accessToken->getKey());
        $this->actingAs($this->user->withAccessToken($token));

        $result = $this->callTool('audit_fixture_write', ['title' => 'x']);

        $this->assertSame('ok', $result['content'][0]['text'] ?? null);

        $entry = $this->onlyEntry();
        $this->assertSame($this->team->id, $entry->getAttribute('team_id'));
        $this->assertSame((string) $this->user->id, (string) $entry->getAttribute('user_id'));
        $this->assertSame(3002, $entry->getAttribute('ocsf_class_uid'));

        $props = $this->onlyEntryProperties();
        $this->assertSame('audit_fixture_write', $props['tool']);
        $this->assertNull($props['action']);
        $this->assertSame('sanctum', $props['token_type']);
        $this->assertSame((string) $issued->accessToken->getKey(), (string) $props['token_id']);
        $this->assertSame('my-pat', $props['token_name']);
        $this->assertSame('success', $props['outcome']);
        $this->assertNull($props['error_code']);
        $this->assertSame(['title'], $props['argument_keys']);
    }

    public function test_session_transient_token_is_not_recorded_as_a_token(): void
    {
        $this->actingAs($this->user->withAccessToken(new ScopedTransientToken));

        $this->callTool('audit_fixture_write');

        $props = $this->onlyEntryProperties();
        $this->assertNull($props['token_type']);
        $this->assertNull($props['token_id']);
        $this->assertNull($props['token_name']);
    }

    public function test_read_only_tool_call_is_not_audited(): void
    {
        $result = $this->callTool('audit_fixture_read');

        $this->assertSame('read', $result['content'][0]['text'] ?? null);
        $this->assertSame(0, $this->auditCount());
    }

    public function test_structured_error_records_its_code(): void
    {
        $this->callTool('audit_fixture_structured_error');

        $props = $this->onlyEntryProperties();
        $this->assertSame('error', $props['outcome']);
        $this->assertSame('NOT_FOUND', $props['error_code']);
    }

    public function test_plain_text_error_records_tool_error(): void
    {
        $this->callTool('audit_fixture_plain_error');

        $props = $this->onlyEntryProperties();
        $this->assertSame('error', $props['outcome']);
        $this->assertSame('TOOL_ERROR', $props['error_code']);
    }

    public function test_client_name_comes_from_client_info(): void
    {
        app()->instance(ProtocolContext::BINDING, new ProtocolContext(
            version: ProtocolVersions::SUPPORTED[0],
            stateless: true,
            clientInfo: ['name' => 'claude-code', 'version' => '1.0'],
        ));

        $this->callTool('audit_fixture_write');

        $props = $this->onlyEntryProperties();
        $this->assertSame('claude-code', $props['client_name']);
        $this->assertSame('1.0', $props['client_version']);
    }

    public function test_client_name_falls_back_to_user_agent(): void
    {
        request()->headers->set('User-Agent', 'test-agent/2.0');

        $this->callTool('audit_fixture_write');

        $props = $this->onlyEntryProperties();
        $this->assertSame('test-agent/2.0', $props['client_name']);
        $this->assertNull($props['client_version']);
    }

    public function test_compact_tool_uses_the_child_tool_read_only_flag(): void
    {
        $this->callTool('audit_fixture_compact', ['action' => 'read']);
        $this->assertSame(0, $this->auditCount());

        $result = $this->callTool('audit_fixture_compact', ['action' => 'write']);
        $this->assertSame('ok', $result['content'][0]['text'] ?? null);

        $props = $this->onlyEntryProperties();
        $this->assertSame('audit_fixture_compact', $props['tool']);
        $this->assertSame('write', $props['action']);
    }

    public function test_argument_values_are_never_stored(): void
    {
        $this->callTool('audit_fixture_write', ['secret' => 'super-secret-value-123']);

        $entry = $this->onlyEntry();
        $props = $entry->getAttribute('properties');
        $this->assertIsArray($props);
        $this->assertSame(['secret'], $props['argument_keys']);
        $this->assertStringNotContainsString('super-secret-value-123', (string) json_encode($props));
        $this->assertStringNotContainsString('super-secret-value-123', (string) $entry->getRawOriginal('properties'));
    }

    public function test_input_required_for_mrtr_client_is_audited_as_input_required(): void
    {
        app()->instance(ProtocolContext::BINDING, new ProtocolContext(
            version: ProtocolVersions::V2026_07_28,
            stateless: true,
        ));

        $result = $this->callTool('audit_fixture_gated');

        $this->assertSame('input_required', $result['resultType'] ?? null);

        $props = $this->onlyEntryProperties();
        $this->assertSame('input_required', $props['outcome']);
        $this->assertNull($props['error_code']);
    }

    public function test_input_required_for_pre_mrtr_client_is_audited_as_the_terminal_fallback(): void
    {
        app()->instance(ProtocolContext::BINDING, new ProtocolContext(
            version: ProtocolVersions::V2025_06_18,
            stateless: true,
        ));

        $result = $this->callTool('audit_fixture_gated');

        $this->assertTrue($result['isError'] ?? null);

        $props = $this->onlyEntryProperties();
        $this->assertSame('error', $props['outcome']);
        $this->assertSame('APPROVAL_PENDING', $props['error_code']);
    }

    public function test_streamed_tool_is_audited_after_the_stream_is_drained(): void
    {
        $request = new JsonRpcRequest(
            id: 1,
            method: 'tools/call',
            params: ['name' => 'audit_fixture_stream', 'arguments' => ['title' => 'x']],
        );

        app()->instance('mcp.request', $request->toRequest());

        try {
            $stream = app(MultiRoundTripCallTool::class)->handle($request, $this->context());
            $this->assertInstanceOf(Generator::class, $stream);
            $this->assertSame(0, $this->auditCount());

            iterator_to_array($stream, false);
        } finally {
            app()->forgetInstance('mcp.request');
        }

        $props = $this->onlyEntryProperties();
        $this->assertSame('audit_fixture_stream', $props['tool']);
        $this->assertSame('success', $props['outcome']);
        $this->assertSame(['title'], $props['argument_keys']);
    }

    public function test_no_team_bound_writes_nothing_and_returns_the_result(): void
    {
        app()->forgetInstance('mcp.team_id');

        $result = $this->callTool('audit_fixture_write');

        $this->assertSame('ok', $result['content'][0]['text'] ?? null);
        $this->assertSame(0, $this->auditCount());
    }
}

class AuditFixtureWriteTool extends Tool
{
    protected string $name = 'audit_fixture_write';

    protected string $description = 'Write fixture.';

    public function handle(Request $request): Response
    {
        return Response::text('ok');
    }
}

#[IsReadOnly]
class AuditFixtureReadTool extends Tool
{
    protected string $name = 'audit_fixture_read';

    protected string $description = 'Read fixture.';

    public function handle(Request $request): Response
    {
        return Response::text('read');
    }
}

class AuditFixtureStructuredErrorTool extends Tool
{
    protected string $name = 'audit_fixture_structured_error';

    protected string $description = 'Structured error fixture.';

    public function handle(Request $request): Response
    {
        return Response::error('{"error":{"code":"NOT_FOUND","message":"missing","retryable":false}}');
    }
}

class AuditFixturePlainErrorTool extends Tool
{
    protected string $name = 'audit_fixture_plain_error';

    protected string $description = 'Plain error fixture.';

    public function handle(Request $request): Response
    {
        return Response::error('something went wrong');
    }
}

class AuditFixtureGatedTool extends Tool
{
    protected string $name = 'audit_fixture_gated';

    protected string $description = 'Gated fixture.';

    public function handle(Request $request): Response
    {
        throw new InputRequiredException(
            inputRequests: [],
            requestState: null,
            fallback: Response::error('{"error":{"code":"APPROVAL_PENDING","message":"awaiting approval","retryable":true}}'),
        );
    }
}

class AuditFixtureStreamTool extends Tool
{
    protected string $name = 'audit_fixture_stream';

    protected string $description = 'Stream fixture.';

    /**
     * @return Generator<Response>
     */
    public function handle(Request $request): Generator
    {
        yield Response::notification('notifications/tools/list_changed');
        yield Response::text('ok');
    }
}

class AuditFixtureCompactTool extends CompactTool
{
    protected string $name = 'audit_fixture_compact';

    protected string $description = 'Compact fixture.';

    protected function toolMap(): array
    {
        return [
            'read' => AuditFixtureReadTool::class,
            'write' => AuditFixtureWriteTool::class,
        ];
    }
}
