<?php

namespace Database\Seeders;

use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowEdge;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PlatformWorkflowsSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $team = Team::withoutGlobalScopes()->where('slug', 'fleetq-platform')->first();
        $user = User::withoutGlobalScopes()->where('email', 'platform@fleetq.net')->first();

        if (! $team || ! $user) {
            $this->command?->warn('Platform team/user not found. Run PlatformTeamSeeder first.');

            return;
        }

        $count = 0;

        foreach ($this->definitions() as $def) {
            $workflow = Workflow::withoutGlobalScopes()->updateOrCreate(
                ['team_id' => $team->id, 'slug' => $def['slug']],
                [
                    'user_id' => $user->id,
                    'name' => $def['name'],
                    'description' => $def['description'],
                    'status' => WorkflowStatus::Active,
                    'version' => 1,
                    'max_loop_iterations' => $def['max_loop_iterations'] ?? 5,
                    'settings' => [],
                ],
            );

            // Rebuild nodes and edges only if freshly created
            if ($workflow->wasRecentlyCreated) {
                $this->buildGraph($workflow, $def['nodes'], $def['edges']);
            }

            $count++;
        }

        $this->command?->info("Platform workflows seeded: {$count}");
    }

    private function buildGraph(Workflow $workflow, array $nodeDefs, array $edgeDefs): void
    {
        $nodeMap = [];

        foreach ($nodeDefs as $index => $nodeDef) {
            $node = WorkflowNode::create([
                'workflow_id' => $workflow->id,
                'agent_id' => null, // agents assigned by installer
                'skill_id' => null,
                'type' => $nodeDef['type'],
                'label' => $nodeDef['label'],
                'position_x' => $nodeDef['position_x'] ?? ($index * 200),
                'position_y' => $nodeDef['position_y'] ?? 200,
                'config' => $nodeDef['config'] ?? [],
                'order' => $index,
            ]);

            $nodeMap[$index] = $node->id;
        }

        foreach ($edgeDefs as $edgeDef) {
            $sourceId = $nodeMap[$edgeDef['source']] ?? null;
            $targetId = $nodeMap[$edgeDef['target']] ?? null;

            if ($sourceId && $targetId) {
                WorkflowEdge::create([
                    'workflow_id' => $workflow->id,
                    'source_node_id' => $sourceId,
                    'target_node_id' => $targetId,
                    'label' => $edgeDef['label'] ?? null,
                    'condition' => $edgeDef['condition'] ?? null,
                    'is_default' => $edgeDef['is_default'] ?? false,
                    'sort_order' => 0,
                ]);
            }
        }
    }

    private function definitions(): array
    {
        return [
            [
                'slug' => 'fleetq-lead-enrichment-pipeline',
                'name' => 'Lead Enrichment Pipeline',
                'description' => 'Automatically qualify, enrich, and score inbound leads. Routes high-scoring leads to outreach and low-scoring leads to a nurture sequence. Assign your SDR agent to the agent nodes.',
                'max_loop_iterations' => 3,
                'nodes' => [
                    ['type' => 'start', 'label' => 'Receive Lead', 'position_x' => 0, 'position_y' => 200],
                    ['type' => 'agent', 'label' => 'Qualify Lead', 'position_x' => 220, 'position_y' => 200, 'config' => ['agent_role' => 'Sales Development Rep', 'task' => 'Score the lead against ICP using Lead Qualifier skill']],
                    ['type' => 'agent', 'label' => 'Enrich Profile', 'position_x' => 440, 'position_y' => 200, 'config' => ['agent_role' => 'Research Agent', 'task' => 'Research the company and add context to the lead profile']],
                    ['type' => 'conditional', 'label' => 'Score ≥ 70?', 'position_x' => 660, 'position_y' => 200, 'config' => ['expression' => 'lead.score >= 70']],
                    ['type' => 'agent', 'label' => 'Write Outreach', 'position_x' => 880, 'position_y' => 100, 'config' => ['agent_role' => 'Sales Development Rep', 'task' => 'Write a personalized cold email for the lead using Cold Email Writer skill']],
                    ['type' => 'agent', 'label' => 'Update CRM', 'position_x' => 1100, 'position_y' => 200, 'config' => ['agent_role' => 'Sales Development Rep', 'task' => 'Create a CRM note summarizing qualification outcome and next steps']],
                    ['type' => 'end', 'label' => 'Done', 'position_x' => 1320, 'position_y' => 200],
                ],
                'edges' => [
                    ['source' => 0, 'target' => 1],
                    ['source' => 1, 'target' => 2],
                    ['source' => 2, 'target' => 3],
                    ['source' => 3, 'target' => 4, 'label' => 'High Score'],
                    ['source' => 3, 'target' => 5, 'is_default' => true, 'label' => 'Low Score'],
                    ['source' => 4, 'target' => 5],
                    ['source' => 5, 'target' => 6],
                ],
            ],

            [
                'slug' => 'fleetq-content-publishing-pipeline',
                'name' => 'Content Publishing Pipeline',
                'description' => 'Research, draft, review, and distribute content across channels with a human approval gate before publishing. Assign your Content Strategy Agent to the agent nodes.',
                'max_loop_iterations' => 3,
                'nodes' => [
                    ['type' => 'start', 'label' => 'Content Request', 'position_x' => 0, 'position_y' => 200],
                    ['type' => 'agent', 'label' => 'Research Topic', 'position_x' => 220, 'position_y' => 200, 'config' => ['agent_role' => 'Research Agent', 'task' => 'Research the topic and collect key data points, angles, and sources']],
                    ['type' => 'agent', 'label' => 'Draft Content', 'position_x' => 440, 'position_y' => 200, 'config' => ['agent_role' => 'Content Strategy Agent', 'task' => 'Write the full content piece based on research findings']],
                    ['type' => 'agent', 'label' => 'Adapt for Channels', 'position_x' => 660, 'position_y' => 200, 'config' => ['agent_role' => 'Content Strategy Agent', 'task' => 'Adapt the content for Twitter, LinkedIn, and email newsletter using Social Media Adapter']],
                    ['type' => 'human_task', 'label' => 'Review & Approve', 'position_x' => 880, 'position_y' => 200, 'config' => ['instructions' => 'Review the drafted content and channel adaptations. Approve to publish or reject with feedback.', 'form_schema' => ['type' => 'object', 'properties' => ['feedback' => ['type' => 'string', 'title' => 'Feedback (if rejecting)']]]]],
                    ['type' => 'agent', 'label' => 'Generate Email Subject Lines', 'position_x' => 1100, 'position_y' => 200, 'config' => ['agent_role' => 'Email Marketer', 'task' => 'Generate 5 A/B-testable email subject lines for the newsletter version']],
                    ['type' => 'end', 'label' => 'Published', 'position_x' => 1320, 'position_y' => 200],
                ],
                'edges' => [
                    ['source' => 0, 'target' => 1],
                    ['source' => 1, 'target' => 2],
                    ['source' => 2, 'target' => 3],
                    ['source' => 3, 'target' => 4],
                    ['source' => 4, 'target' => 5, 'label' => 'Approved'],
                    ['source' => 5, 'target' => 6],
                ],
            ],

            [
                'slug' => 'fleetq-incident-response-workflow',
                'name' => 'Incident Response Workflow',
                'description' => 'Detect, diagnose, and respond to operational incidents. Routes critical incidents to a human approver before automated remediation. Assign your Operations Coordinator to agent nodes.',
                'max_loop_iterations' => 3,
                'nodes' => [
                    ['type' => 'start', 'label' => 'Anomaly Detected', 'position_x' => 0, 'position_y' => 200],
                    ['type' => 'agent', 'label' => 'Explain Anomaly', 'position_x' => 220, 'position_y' => 200, 'config' => ['agent_role' => 'Data Analyst', 'task' => 'Analyze the anomaly and explain what happened and probable causes']],
                    ['type' => 'agent', 'label' => 'Assess Risk', 'position_x' => 440, 'position_y' => 200, 'config' => ['agent_role' => 'Operations Coordinator', 'task' => 'Assess the risk level of the incident and recommend response']],
                    ['type' => 'conditional', 'label' => 'Critical?', 'position_x' => 660, 'position_y' => 200, 'config' => ['expression' => 'risk.level == "critical"']],
                    ['type' => 'human_task', 'label' => 'Authorize Response', 'position_x' => 880, 'position_y' => 100, 'config' => ['instructions' => 'Critical incident detected. Review the risk assessment and authorize the recommended response action.', 'form_schema' => ['type' => 'object', 'properties' => ['authorized_action' => ['type' => 'string', 'title' => 'Authorized action']]]]],
                    ['type' => 'agent', 'label' => 'Execute Response', 'position_x' => 1100, 'position_y' => 200, 'config' => ['agent_role' => 'Operations Coordinator', 'task' => 'Execute the approved response action and verify resolution']],
                    ['type' => 'agent', 'label' => 'Document Incident', 'position_x' => 1320, 'position_y' => 200, 'config' => ['agent_role' => 'Operations Coordinator', 'task' => 'Write an incident report with timeline, root cause, actions taken, and prevention measures']],
                    ['type' => 'end', 'label' => 'Resolved', 'position_x' => 1540, 'position_y' => 200],
                ],
                'edges' => [
                    ['source' => 0, 'target' => 1],
                    ['source' => 1, 'target' => 2],
                    ['source' => 2, 'target' => 3],
                    ['source' => 3, 'target' => 4, 'label' => 'Critical'],
                    ['source' => 3, 'target' => 5, 'is_default' => true, 'label' => 'Non-Critical'],
                    ['source' => 4, 'target' => 5, 'label' => 'Authorized'],
                    ['source' => 5, 'target' => 6],
                    ['source' => 6, 'target' => 7],
                ],
            ],

            [
                'slug' => 'fleetq-customer-onboarding-workflow',
                'name' => 'Customer Onboarding Workflow',
                'description' => 'Guide new customers through onboarding: document the kickoff call, answer initial questions, and create a success plan. Assign your Customer Onboarding Agent to agent nodes.',
                'max_loop_iterations' => 2,
                'nodes' => [
                    ['type' => 'start', 'label' => 'New Customer', 'position_x' => 0, 'position_y' => 200],
                    ['type' => 'agent', 'label' => 'Summarize Kickoff Call', 'position_x' => 220, 'position_y' => 200, 'config' => ['agent_role' => 'Customer Onboarding Agent', 'task' => 'Summarize kickoff call notes into decisions, action items, and success criteria']],
                    ['type' => 'agent', 'label' => 'Answer Setup Questions', 'position_x' => 440, 'position_y' => 200, 'config' => ['agent_role' => 'Customer Onboarding Agent', 'task' => 'Answer the customer\'s initial setup questions using the knowledge base']],
                    ['type' => 'human_task', 'label' => 'Review Success Plan', 'position_x' => 660, 'position_y' => 200, 'config' => ['instructions' => 'Review the drafted success plan and customize it for the customer before sending.', 'form_schema' => ['type' => 'object', 'properties' => ['approved' => ['type' => 'boolean', 'title' => 'Approve plan?']]]]],
                    ['type' => 'agent', 'label' => 'Create Success Plan', 'position_x' => 880, 'position_y' => 200, 'config' => ['agent_role' => 'Customer Onboarding Agent', 'task' => 'Generate a 30-60-90 day customer success plan based on their goals and setup call']],
                    ['type' => 'end', 'label' => 'Onboarded', 'position_x' => 1100, 'position_y' => 200],
                ],
                'edges' => [
                    ['source' => 0, 'target' => 1],
                    ['source' => 1, 'target' => 2],
                    ['source' => 2, 'target' => 3],
                    ['source' => 3, 'target' => 4, 'label' => 'Approved'],
                    ['source' => 4, 'target' => 5],
                ],
            ],

            [
                'slug' => 'fleetq-support-escalation-workflow',
                'name' => 'Support Escalation Workflow',
                'description' => 'Monitor incoming tickets for escalation signals, check SLA compliance, and prepare escalation briefings for management. Assign your Escalation Analyst to agent nodes.',
                'max_loop_iterations' => 2,
                'nodes' => [
                    ['type' => 'start', 'label' => 'Ticket Batch', 'position_x' => 0, 'position_y' => 200],
                    ['type' => 'agent', 'label' => 'Analyze Sentiment', 'position_x' => 220, 'position_y' => 200, 'config' => ['agent_role' => 'Escalation Analyst', 'task' => 'Analyze sentiment and escalation risk for each ticket']],
                    ['type' => 'agent', 'label' => 'Check SLA Status', 'position_x' => 440, 'position_y' => 200, 'config' => ['agent_role' => 'Escalation Analyst', 'task' => 'Check SLA compliance status and flag at-risk and breached tickets']],
                    ['type' => 'agent', 'label' => 'Prepare Escalation Brief', 'position_x' => 660, 'position_y' => 200, 'config' => ['agent_role' => 'Escalation Analyst', 'task' => 'Prepare an escalation briefing for manager with context, sentiment, SLA status, and recommended actions for each escalation-worthy ticket']],
                    ['type' => 'end', 'label' => 'Brief Ready', 'position_x' => 880, 'position_y' => 200],
                ],
                'edges' => [
                    ['source' => 0, 'target' => 1],
                    ['source' => 1, 'target' => 2],
                    ['source' => 2, 'target' => 3],
                    ['source' => 3, 'target' => 4],
                ],
            ],

            [
                'slug' => 'fleetq-invoice-processing-workflow',
                'name' => 'Invoice Processing Workflow',
                'description' => 'Extract data from invoice documents, validate against purchase orders, and route to approval or flag exceptions. Assign your Data Analyst to agent nodes.',
                'max_loop_iterations' => 2,
                'nodes' => [
                    ['type' => 'start', 'label' => 'Invoice Received', 'position_x' => 0, 'position_y' => 200],
                    ['type' => 'agent', 'label' => 'Extract Invoice Data', 'position_x' => 220, 'position_y' => 200, 'config' => ['agent_role' => 'Data Analyst', 'task' => 'Extract structured data from the invoice: vendor, amount, line items, dates, PO number']],
                    ['type' => 'agent', 'label' => 'Validate & Risk Check', 'position_x' => 440, 'position_y' => 200, 'config' => ['agent_role' => 'Data Analyst', 'task' => 'Validate extracted data, check for anomalies or discrepancies, assess payment risk']],
                    ['type' => 'conditional', 'label' => 'Exceptions?', 'position_x' => 660, 'position_y' => 200, 'config' => ['expression' => 'validation.has_exceptions']],
                    ['type' => 'human_task', 'label' => 'Review Exception', 'position_x' => 880, 'position_y' => 100, 'config' => ['instructions' => 'Invoice has validation exceptions. Review the extracted data and decide: approve despite exceptions, reject, or request correction.', 'form_schema' => ['type' => 'object', 'properties' => ['decision' => ['type' => 'string', 'enum' => ['approve', 'reject', 'request_correction'], 'title' => 'Decision']]]]],
                    ['type' => 'end', 'label' => 'Processed', 'position_x' => 1100, 'position_y' => 200],
                ],
                'edges' => [
                    ['source' => 0, 'target' => 1],
                    ['source' => 1, 'target' => 2],
                    ['source' => 2, 'target' => 3],
                    ['source' => 3, 'target' => 4, 'label' => 'Has Exceptions'],
                    ['source' => 3, 'target' => 5, 'is_default' => true, 'label' => 'Clean'],
                    ['source' => 4, 'target' => 5],
                ],
            ],

            [
                'slug' => 'fleetq-web-dev-cycle',
                'name' => 'Web Dev Cycle',
                'description' => 'Full autonomous web project lifecycle: plan → build → test → lint → review → deploy. Covers feature development, automated testing, code quality checks, human approval, and one-click deployment. Assign your Developer Agent and QA Agent to the corresponding nodes.',
                'max_loop_iterations' => 3,
                'nodes' => [
                    ['type' => 'start', 'label' => 'Feature Request', 'position_x' => 0, 'position_y' => 200],
                    ['type' => 'agent', 'label' => 'Plan Implementation', 'position_x' => 220, 'position_y' => 200, 'config' => ['agent_role' => 'Developer Agent', 'task' => 'Analyse the feature request and produce an implementation plan: list files to change, functions to add, and edge cases to handle']],
                    ['type' => 'agent', 'label' => 'Implement Feature', 'position_x' => 440, 'position_y' => 200, 'config' => ['agent_role' => 'Developer Agent', 'task' => 'Implement the feature according to the plan. Write clean, well-structured code following the project conventions']],
                    ['type' => 'agent', 'label' => 'Run Tests', 'position_x' => 660, 'position_y' => 200, 'config' => ['agent_role' => 'QA Agent', 'task' => 'Run the test suite and report pass/fail counts. If tests fail, describe the failures in detail']],
                    ['type' => 'conditional', 'label' => 'Tests Pass?', 'position_x' => 880, 'position_y' => 200, 'config' => ['expression' => 'tests.failed == 0']],
                    ['type' => 'agent', 'label' => 'Fix Failures', 'position_x' => 1100, 'position_y' => 350, 'config' => ['agent_role' => 'Developer Agent', 'task' => 'Fix the failing tests identified in the previous step. Address each failure and re-run to confirm resolution']],
                    ['type' => 'agent', 'label' => 'Run Linter', 'position_x' => 1100, 'position_y' => 100, 'config' => ['agent_role' => 'QA Agent', 'task' => 'Run the linter and static analysis. Report any style violations or type errors']],
                    ['type' => 'agent', 'label' => 'Code Review', 'position_x' => 1320, 'position_y' => 200, 'config' => ['agent_role' => 'Developer Agent', 'task' => 'Review the implementation for security issues, performance concerns, and adherence to best practices. Summarise findings']],
                    ['type' => 'human_task', 'label' => 'Approve & Deploy', 'position_x' => 1540, 'position_y' => 200, 'config' => ['instructions' => 'Review the implementation summary, test results, lint report, and code review findings. Approve to trigger deployment or reject with feedback.', 'form_schema' => ['type' => 'object', 'properties' => ['environment' => ['type' => 'string', 'enum' => ['staging', 'production'], 'title' => 'Deploy to'], 'feedback' => ['type' => 'string', 'title' => 'Feedback (if rejecting)']]]]],
                    ['type' => 'agent', 'label' => 'Deploy', 'position_x' => 1760, 'position_y' => 200, 'config' => ['agent_role' => 'Developer Agent', 'task' => 'Deploy the feature to the environment specified in the approval step. Verify the deployment succeeded and report the live URL']],
                    ['type' => 'end', 'label' => 'Shipped', 'position_x' => 1980, 'position_y' => 200],
                ],
                'edges' => [
                    ['source' => 0, 'target' => 1],
                    ['source' => 1, 'target' => 2],
                    ['source' => 2, 'target' => 3],
                    ['source' => 3, 'target' => 4],
                    ['source' => 4, 'target' => 5, 'label' => 'Failures', 'condition' => 'tests.failed > 0'],
                    ['source' => 4, 'target' => 6, 'is_default' => true, 'label' => 'Passing'],
                    ['source' => 5, 'target' => 6, 'label' => 'Fixed'],
                    ['source' => 6, 'target' => 7],
                    ['source' => 7, 'target' => 8],
                    ['source' => 8, 'target' => 9, 'label' => 'Approved'],
                    ['source' => 9, 'target' => 10],
                ],
            ],
        ];
    }
}
