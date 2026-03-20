<?php

namespace Tests\Feature\Marketplace;

use App\Domain\Agent\Models\Agent;
use App\Domain\Marketplace\Actions\InstallFromMarketplaceAction;
use App\Domain\Marketplace\Models\MarketplaceInstallation;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Models\Skill;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BundleInstallTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private InstallFromMarketplaceAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        $this->action = app(InstallFromMarketplaceAction::class);
    }

    public function test_bundle_install_creates_all_items(): void
    {
        $listing = $this->createBundleListing([
            'items' => [
                [
                    'type' => 'skill',
                    'ref_key' => 'classifier',
                    'name' => 'Ticket Classifier',
                    'description' => 'Classifies tickets',
                    'snapshot' => [
                        'type' => 'llm',
                        'input_schema' => ['ticket_text' => 'string'],
                        'output_schema' => ['category' => 'string'],
                        'configuration' => [],
                        'system_prompt' => 'Classify tickets',
                        'risk_level' => 'low',
                    ],
                ],
                [
                    'type' => 'agent',
                    'ref_key' => 'triage_agent',
                    'name' => 'Triage Agent',
                    'description' => 'Triages support tickets',
                    'snapshot' => [
                        'role' => 'Support Triage',
                        'goal' => 'Route tickets to correct team',
                        'provider' => 'anthropic',
                        'model' => 'claude-sonnet-4-5',
                        'capabilities' => ['classification'],
                        'constraints' => [],
                    ],
                ],
            ],
            'entity_refs' => [],
        ]);

        $installation = $this->action->execute($listing, $this->team->id, $this->user->id);

        $this->assertInstanceOf(MarketplaceInstallation::class, $installation);
        $this->assertNotNull($installation->bundle_metadata);
        $this->assertArrayHasKey('installed_ids', $installation->bundle_metadata);
        $this->assertArrayHasKey('classifier', $installation->bundle_metadata['installed_ids']);
        $this->assertArrayHasKey('triage_agent', $installation->bundle_metadata['installed_ids']);

        // Verify actual models were created
        $this->assertDatabaseHas('skills', [
            'id' => $installation->bundle_metadata['installed_ids']['classifier'],
            'team_id' => $this->team->id,
            'name' => 'Ticket Classifier',
        ]);

        $this->assertDatabaseHas('agents', [
            'id' => $installation->bundle_metadata['installed_ids']['triage_agent'],
            'team_id' => $this->team->id,
            'name' => 'Triage Agent',
        ]);
    }

    public function test_bundle_install_wires_workflow_node_to_agent(): void
    {
        $listing = $this->createBundleListing([
            'items' => [
                [
                    'type' => 'agent',
                    'ref_key' => 'my_agent',
                    'name' => 'Worker Agent',
                    'description' => 'Does work',
                    'snapshot' => [
                        'role' => 'Worker',
                        'goal' => 'Execute tasks',
                        'provider' => 'anthropic',
                        'model' => 'claude-sonnet-4-5',
                        'capabilities' => [],
                        'constraints' => [],
                    ],
                ],
                [
                    'type' => 'workflow',
                    'ref_key' => 'my_workflow',
                    'name' => 'Test Workflow',
                    'description' => 'A test workflow',
                    'snapshot' => [
                        'description' => 'Test workflow',
                        'max_loop_iterations' => 5,
                        'settings' => [],
                        'nodes' => [
                            ['id' => 'n1', 'type' => 'start', 'label' => 'Start', 'position_x' => 0, 'position_y' => 0, 'config' => [], 'order' => 0],
                            ['id' => 'n2', 'type' => 'agent', 'label' => 'Process', 'position_x' => 100, 'position_y' => 0, 'config' => [], 'order' => 1],
                            ['id' => 'n3', 'type' => 'end', 'label' => 'End', 'position_x' => 200, 'position_y' => 0, 'config' => [], 'order' => 2],
                        ],
                        'edges' => [
                            ['source_node_id' => 'n1', 'target_node_id' => 'n2', 'is_default' => true, 'sort_order' => 0],
                            ['source_node_id' => 'n2', 'target_node_id' => 'n3', 'is_default' => true, 'sort_order' => 0],
                        ],
                    ],
                ],
            ],
            'entity_refs' => [
                [
                    'workflow_ref' => 'my_workflow',
                    'node_label' => 'Process',
                    'agent_ref' => 'my_agent',
                ],
            ],
        ]);

        $installation = $this->action->execute($listing, $this->team->id, $this->user->id);

        $workflowId = $installation->bundle_metadata['installed_ids']['my_workflow'];
        $agentId = $installation->bundle_metadata['installed_ids']['my_agent'];

        // The "Process" node should now point to the installed agent
        $processNode = WorkflowNode::where('workflow_id', $workflowId)
            ->where('label', 'Process')
            ->first();

        $this->assertNotNull($processNode);
        $this->assertEquals($agentId, $processNode->agent_id);
    }

    public function test_bundle_install_wires_workflow_node_to_skill(): void
    {
        $listing = $this->createBundleListing([
            'items' => [
                [
                    'type' => 'skill',
                    'ref_key' => 'my_skill',
                    'name' => 'Analyzer Skill',
                    'description' => 'Analyzes data',
                    'snapshot' => [
                        'type' => 'llm',
                        'input_schema' => [],
                        'output_schema' => [],
                        'configuration' => [],
                        'system_prompt' => 'Analyze',
                        'risk_level' => 'low',
                    ],
                ],
                [
                    'type' => 'workflow',
                    'ref_key' => 'my_workflow',
                    'name' => 'Analysis Workflow',
                    'description' => 'Runs analysis',
                    'snapshot' => [
                        'description' => 'Analysis workflow',
                        'max_loop_iterations' => 5,
                        'settings' => [],
                        'nodes' => [
                            ['id' => 'n1', 'type' => 'start', 'label' => 'Start', 'position_x' => 0, 'position_y' => 0, 'config' => [], 'order' => 0],
                            ['id' => 'n2', 'type' => 'agent', 'label' => 'Analyze', 'position_x' => 100, 'position_y' => 0, 'config' => [], 'order' => 1],
                            ['id' => 'n3', 'type' => 'end', 'label' => 'End', 'position_x' => 200, 'position_y' => 0, 'config' => [], 'order' => 2],
                        ],
                        'edges' => [
                            ['source_node_id' => 'n1', 'target_node_id' => 'n2', 'is_default' => true, 'sort_order' => 0],
                            ['source_node_id' => 'n2', 'target_node_id' => 'n3', 'is_default' => true, 'sort_order' => 0],
                        ],
                    ],
                ],
            ],
            'entity_refs' => [
                [
                    'workflow_ref' => 'my_workflow',
                    'node_label' => 'Analyze',
                    'skill_ref' => 'my_skill',
                ],
            ],
        ]);

        $installation = $this->action->execute($listing, $this->team->id, $this->user->id);

        $workflowId = $installation->bundle_metadata['installed_ids']['my_workflow'];
        $skillId = $installation->bundle_metadata['installed_ids']['my_skill'];

        $analyzeNode = WorkflowNode::where('workflow_id', $workflowId)
            ->where('label', 'Analyze')
            ->first();

        $this->assertNotNull($analyzeNode);
        $this->assertEquals($skillId, $analyzeNode->skill_id);
    }

    public function test_bundle_install_wires_agent_skill_pivot(): void
    {
        $listing = $this->createBundleListing([
            'items' => [
                [
                    'type' => 'skill',
                    'ref_key' => 'my_skill',
                    'name' => 'Helper Skill',
                    'description' => 'Helps',
                    'snapshot' => [
                        'type' => 'llm',
                        'input_schema' => [],
                        'output_schema' => [],
                        'configuration' => [],
                        'system_prompt' => 'Help',
                        'risk_level' => 'low',
                    ],
                ],
                [
                    'type' => 'agent',
                    'ref_key' => 'my_agent',
                    'name' => 'Smart Agent',
                    'description' => 'Smart',
                    'snapshot' => [
                        'role' => 'Helper',
                        'goal' => 'Help users',
                        'provider' => 'anthropic',
                        'model' => 'claude-sonnet-4-5',
                        'capabilities' => [],
                        'constraints' => [],
                    ],
                ],
            ],
            'entity_refs' => [
                [
                    'agent_ref' => 'my_agent',
                    'skill_ref' => 'my_skill',
                ],
            ],
        ]);

        $installation = $this->action->execute($listing, $this->team->id, $this->user->id);

        $agentId = $installation->bundle_metadata['installed_ids']['my_agent'];
        $skillId = $installation->bundle_metadata['installed_ids']['my_skill'];

        $agent = Agent::find($agentId);
        $this->assertTrue($agent->skills()->where('skills.id', $skillId)->exists());
    }

    public function test_bundle_install_preserves_setup_hints(): void
    {
        $hints = [
            'Configure your SMTP credentials in Settings → Credentials',
            'Set up a Slack webhook for notifications',
        ];

        $listing = $this->createBundleListing([
            'items' => [
                [
                    'type' => 'skill',
                    'ref_key' => 'test_skill',
                    'name' => 'Test Skill',
                    'description' => 'Test',
                    'snapshot' => [
                        'type' => 'llm',
                        'input_schema' => [],
                        'output_schema' => [],
                        'configuration' => [],
                        'system_prompt' => null,
                        'risk_level' => 'low',
                    ],
                ],
            ],
            'entity_refs' => [],
            'setup_hints' => $hints,
        ]);

        $installation = $this->action->execute($listing, $this->team->id, $this->user->id);

        $this->assertEquals($hints, $installation->bundle_metadata['setup_hints']);
    }

    public function test_bundle_install_preserves_required_credentials(): void
    {
        $credentials = [
            ['type' => 'api_key', 'service' => 'openai', 'purpose' => 'LLM inference'],
            ['type' => 'oauth2', 'service' => 'slack', 'purpose' => 'Send notifications'],
        ];

        $listing = $this->createBundleListing([
            'items' => [
                [
                    'type' => 'agent',
                    'ref_key' => 'test_agent',
                    'name' => 'Test Agent',
                    'description' => 'Test',
                    'snapshot' => [
                        'role' => 'Test',
                        'goal' => 'Test',
                        'provider' => 'anthropic',
                        'model' => 'claude-sonnet-4-5',
                        'capabilities' => [],
                        'constraints' => [],
                    ],
                ],
            ],
            'entity_refs' => [],
            'required_credentials' => $credentials,
        ]);

        $installation = $this->action->execute($listing, $this->team->id, $this->user->id);

        $this->assertEquals($credentials, $installation->bundle_metadata['required_credentials']);
    }

    public function test_bundle_install_increments_install_count(): void
    {
        $listing = $this->createBundleListing([
            'items' => [
                [
                    'type' => 'skill',
                    'ref_key' => 'sk',
                    'name' => 'Skill',
                    'description' => 'Skill',
                    'snapshot' => [
                        'type' => 'llm',
                        'input_schema' => [],
                        'output_schema' => [],
                        'configuration' => [],
                        'system_prompt' => null,
                        'risk_level' => 'low',
                    ],
                ],
            ],
            'entity_refs' => [],
        ]);

        $this->assertEquals(0, $listing->install_count);

        $this->action->execute($listing, $this->team->id, $this->user->id);

        $listing->refresh();
        $this->assertEquals(1, $listing->install_count);
    }

    public function test_bundle_install_full_scenario_with_multiple_refs(): void
    {
        $listing = $this->createBundleListing([
            'items' => [
                [
                    'type' => 'skill',
                    'ref_key' => 'classifier',
                    'name' => 'Ticket Classifier',
                    'description' => 'Classifies tickets',
                    'snapshot' => [
                        'type' => 'llm',
                        'input_schema' => [],
                        'output_schema' => [],
                        'configuration' => [],
                        'system_prompt' => 'Classify',
                        'risk_level' => 'low',
                    ],
                ],
                [
                    'type' => 'agent',
                    'ref_key' => 'triage',
                    'name' => 'Triage Agent',
                    'description' => 'Triages',
                    'snapshot' => [
                        'role' => 'Triage',
                        'goal' => 'Route tickets',
                        'provider' => 'anthropic',
                        'model' => 'claude-sonnet-4-5',
                        'capabilities' => [],
                        'constraints' => [],
                    ],
                ],
                [
                    'type' => 'workflow',
                    'ref_key' => 'support_flow',
                    'name' => 'Support Workflow',
                    'description' => 'Full support flow',
                    'snapshot' => [
                        'description' => 'Support workflow',
                        'max_loop_iterations' => 5,
                        'settings' => [],
                        'nodes' => [
                            ['id' => 'n1', 'type' => 'start', 'label' => 'Start', 'position_x' => 0, 'position_y' => 0, 'config' => [], 'order' => 0],
                            ['id' => 'n2', 'type' => 'agent', 'label' => 'Classify Ticket', 'position_x' => 100, 'position_y' => 0, 'config' => [], 'order' => 1],
                            ['id' => 'n3', 'type' => 'agent', 'label' => 'Route Ticket', 'position_x' => 200, 'position_y' => 0, 'config' => [], 'order' => 2],
                            ['id' => 'n4', 'type' => 'end', 'label' => 'End', 'position_x' => 300, 'position_y' => 0, 'config' => [], 'order' => 3],
                        ],
                        'edges' => [
                            ['source_node_id' => 'n1', 'target_node_id' => 'n2', 'is_default' => true, 'sort_order' => 0],
                            ['source_node_id' => 'n2', 'target_node_id' => 'n3', 'is_default' => true, 'sort_order' => 0],
                            ['source_node_id' => 'n3', 'target_node_id' => 'n4', 'is_default' => true, 'sort_order' => 0],
                        ],
                    ],
                ],
            ],
            'entity_refs' => [
                // Workflow node → agent
                ['workflow_ref' => 'support_flow', 'node_label' => 'Route Ticket', 'agent_ref' => 'triage'],
                // Workflow node → skill (via skill_ref)
                ['workflow_ref' => 'support_flow', 'node_label' => 'Classify Ticket', 'skill_ref' => 'classifier'],
                // Agent → skill pivot
                ['agent_ref' => 'triage', 'skill_ref' => 'classifier'],
            ],
            'setup_hints' => ['Connect your helpdesk API key'],
            'required_credentials' => [
                ['type' => 'api_key', 'service' => 'zendesk', 'purpose' => 'Ticket sync'],
            ],
        ]);

        $installation = $this->action->execute($listing, $this->team->id, $this->user->id);

        $ids = $installation->bundle_metadata['installed_ids'];
        $this->assertCount(3, $ids);

        // Verify workflow node → agent wiring
        $routeNode = WorkflowNode::where('workflow_id', $ids['support_flow'])
            ->where('label', 'Route Ticket')
            ->first();
        $this->assertEquals($ids['triage'], $routeNode->agent_id);

        // Verify workflow node → skill wiring
        $classifyNode = WorkflowNode::where('workflow_id', $ids['support_flow'])
            ->where('label', 'Classify Ticket')
            ->first();
        $this->assertEquals($ids['classifier'], $classifyNode->skill_id);

        // Verify agent → skill pivot
        $agent = Agent::find($ids['triage']);
        $this->assertTrue($agent->skills()->where('skills.id', $ids['classifier'])->exists());

        // Verify metadata
        $this->assertEquals(['Connect your helpdesk API key'], $installation->bundle_metadata['setup_hints']);
        $this->assertCount(1, $installation->bundle_metadata['required_credentials']);
        $this->assertEquals('zendesk', $installation->bundle_metadata['required_credentials'][0]['service']);
    }

    private function createBundleListing(array $snapshot): MarketplaceListing
    {
        return MarketplaceListing::create([
            'team_id' => $this->team->id,
            'published_by' => $this->user->id,
            'type' => 'bundle',
            'listable_id' => null,
            'name' => 'Test Bundle',
            'slug' => 'test-bundle-'.uniqid(),
            'description' => 'A test bundle listing',
            'status' => 'published',
            'visibility' => 'public',
            'version' => '1.0.0',
            'configuration_snapshot' => $snapshot,
            'install_count' => 0,
            'avg_rating' => 0,
            'review_count' => 0,
        ]);
    }
}
