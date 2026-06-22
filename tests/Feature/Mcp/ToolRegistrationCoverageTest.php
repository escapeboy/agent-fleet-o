<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\AgentFleetServer;
use App\Mcp\Servers\CompactMcpServer;
use Laravel\Mcp\Server\Tool;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Guards the hand-maintained AgentFleetServer::$tools registry against silent
 * omission: every concrete MCP Tool under app/Mcp/Tools MUST be either
 * registered in $tools OR explicitly listed in UNREGISTERED_BY_DESIGN with a
 * reason. A newly-added tool that is neither fails this test, forcing a
 * conscious "register or withhold" decision instead of being silently
 * unreachable.
 *
 * This keeps the registry's secure-by-default curation (powerful tools are NOT
 * exposed by accident) while eliminating the forgotten-registration class of
 * bug that the manual array invites.
 */
class ToolRegistrationCoverageTest extends TestCase
{
    /**
     * Concrete tools intentionally NOT registered in AgentFleetServer.
     * Keyed by class-string => reason. Adding a tool here is a deliberate
     * decision to withhold it from the default MCP surface.
     *
     * @var array<class-string, string>
     */
    private const UNREGISTERED_BY_DESIGN = [
        // --- Privileged dev-infra (git write / CI dispatch / release / exec) ---
        // Powerful tools deliberately kept off the default agent surface. Promote
        // to a server's $tools only with an explicit per-tool exposure decision.
        'App\Mcp\Tools\GitRepository\GitChangelogTool' => 'privileged dev-infra; withheld',
        'App\Mcp\Tools\GitRepository\GitPullRequestCloseTool' => 'privileged dev-infra (git write); withheld',
        'App\Mcp\Tools\GitRepository\GitPullRequestMergeTool' => 'privileged dev-infra (git write); withheld',
        'App\Mcp\Tools\GitRepository\GitPullRequestStatusTool' => 'privileged dev-infra; withheld',
        'App\Mcp\Tools\GitRepository\GitReleaseTool' => 'privileged dev-infra (release); withheld',
        'App\Mcp\Tools\GitRepository\GitWorkflowDispatchTool' => 'privileged dev-infra (CI dispatch); withheld',
        'App\Mcp\Tools\GitRepository\VersionBumpTool' => 'privileged dev-infra (version bump); withheld',
        'App\Mcp\Tools\Testing\LintTool' => 'privileged dev-infra (code exec); withheld',
        'App\Mcp\Tools\Testing\TestRunnerTool' => 'privileged dev-infra (code exec); withheld',

        // --- Sensitive account mutation ---
        'App\Mcp\Tools\Profile\ProfilePasswordUpdateTool' => 'sensitive account mutation; withheld',

        // --- Internal / meta / admin-observability ---
        'App\Mcp\Tools\System\ShadowTrafficSummaryTool' => 'internal observability; not a public agent tool',
        'App\Mcp\Tools\Shared\McpToolCatalogTool' => 'meta tool; not part of the public surface',
        'App\Mcp\Tools\Shared\McpToolPreferencesTool' => 'meta tool; not part of the public surface',
        'App\Mcp\Tools\Boruna\BorunaPolicyValidateTool' => 'internal Boruna policy validation; withheld',

        // --- Candidates for registration (review): not in any server, no obvious
        //     reason to withhold. Founder to decide whether to expose. ---
        'App\Mcp\Tools\Experiment\ExperimentActivityTimelineTool' => 'review: candidate for registration',
        'App\Mcp\Tools\Experiment\ExperimentSandboxFilesTool' => 'review: candidate for registration',
        'App\Mcp\Tools\Integration\IntegrationManageTool' => 'review: likely superseded by Compact\IntegrationManageTool',
        'App\Mcp\Tools\Project\ProjectSnapshotCreateTool' => 'review: candidate for registration',
        'App\Mcp\Tools\Project\ProjectSnapshotListTool' => 'review: candidate for registration',
        'App\Mcp\Tools\Project\ProjectSnapshotRestoreTool' => 'review: candidate for registration',
        'App\Mcp\Tools\Workflow\WorkflowGatewayTool' => 'review: candidate for registration',
    ];

    public function test_every_concrete_tool_is_registered_or_explicitly_withheld(): void
    {
        $registered = $this->registeredTools();
        $unexpected = [];

        foreach ($this->discoverConcreteTools() as $class) {
            if (in_array($class, $registered, true)) {
                continue;
            }

            if (array_key_exists($class, self::UNREGISTERED_BY_DESIGN)) {
                continue;
            }

            $unexpected[] = $class;
        }

        sort($unexpected);

        $this->assertSame(
            [],
            $unexpected,
            count($unexpected).' concrete MCP tool(s) are neither registered in AgentFleetServer::$tools '
            ."nor listed in UNREGISTERED_BY_DESIGN.\nEither register them or add them to the allowlist "
            ."with a reason:\n  ".implode("\n  ", $unexpected),
        );
    }

    /**
     * Union of tools registered across ALL MCP servers (full + compact).
     *
     * @return list<class-string>
     */
    private function registeredTools(): array
    {
        $tools = [];

        foreach ([AgentFleetServer::class, CompactMcpServer::class] as $server) {
            /** @var array<int, class-string> $serverTools */
            $serverTools = (new ReflectionClass($server))->getDefaultProperties()['tools'] ?? [];
            $tools = array_merge($tools, $serverTools);
        }

        return array_values(array_unique($tools));
    }

    /**
     * @return iterable<class-string>
     */
    private function discoverConcreteTools(): iterable
    {
        $finder = (new Finder)
            ->files()
            ->in(app_path('Mcp/Tools'))
            ->name('*Tool.php');

        foreach ($finder as $file) {
            $relative = str_replace(
                [app_path().'/', '/', '.php'],
                ['', '\\', ''],
                $file->getRealPath(),
            );
            $class = 'App\\'.$relative;

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Tool::class) || ! $reflection->isInstantiable()) {
                continue;
            }

            yield $class;
        }
    }
}
