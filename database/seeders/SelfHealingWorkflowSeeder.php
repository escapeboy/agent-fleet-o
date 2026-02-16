<?php

namespace Database\Seeders;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowEdge;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SelfHealingWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $team = Team::first();
        $user = User::first();

        if (! $team || ! $user) {
            $this->command->warn('No team/user found. Run app:install first.');

            return;
        }

        // Create or find the three agents
        $detectAgent = $this->ensureAgent($team, $user, [
            'name' => 'Issue Detector',
            'role' => 'Monitoring agent',
            'goal' => 'Detect anomalies, errors, and performance issues in system metrics, logs, and health endpoints.',
            'backstory' => 'You are a vigilant monitoring agent specialized in detecting system anomalies. You analyze logs, metrics, and health check results to identify issues before they escalate. You output structured findings with severity levels.',
        ]);

        $diagnoseAgent = $this->ensureAgent($team, $user, [
            'name' => 'Issue Diagnostician',
            'role' => 'Diagnostic agent',
            'goal' => 'Analyze detected issues, identify root causes, and determine the best remediation strategy.',
            'backstory' => 'You are an expert diagnostician who analyzes system issues in depth. Given an anomaly report, you investigate logs, trace error chains, and produce a diagnosis with recommended actions. You classify issues and suggest whether automated remediation is safe.',
        ]);

        $remediateAgent = $this->ensureAgent($team, $user, [
            'name' => 'Auto Remediator',
            'role' => 'Remediation agent',
            'goal' => 'Execute safe remediation actions based on the diagnosis. In watcher mode, only report; in autonomous mode, take corrective action.',
            'backstory' => 'You are a careful remediation agent. Based on a diagnosis, you execute the safest corrective action available. In watcher/read-only mode, you generate a detailed incident report instead. You always document what was done and verify the fix.',
        ]);

        // Create the workflow
        $workflow = Workflow::updateOrCreate(
            ['team_id' => $team->id, 'slug' => 'self-healing-monitor'],
            [
                'user_id' => $user->id,
                'name' => 'Self-Healing Monitor',
                'description' => 'Autonomous monitoring workflow: detect issues, diagnose root cause, and remediate automatically. In watcher mode, agents only use read/safe tools and produce reports instead of taking action.',
                'status' => WorkflowStatus::Active,
                'version' => 1,
                'max_loop_iterations' => 5,
            ],
        );

        // Clear existing nodes/edges to rebuild
        $workflow->edges()->delete();
        $workflow->nodes()->delete();

        // Create nodes (DAG: Start -> Detect -> Conditional -> Diagnose -> Remediate -> End)
        $startNode = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'start',
            'label' => 'Start',
            'position_x' => 400,
            'position_y' => 50,
            'order' => 0,
        ]);

        $detectNode = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'agent',
            'agent_id' => $detectAgent->id,
            'label' => 'Detect Issues',
            'position_x' => 400,
            'position_y' => 200,
            'order' => 1,
            'config' => [
                'timeout' => 120,
                'retries' => 1,
                'prompt' => 'Scan the system for anomalies. Check available health endpoints, logs, and metrics. Output a JSON object with: {"issues_found": true/false, "severity": "none|low|medium|high|critical", "findings": [...]}',
            ],
        ]);

        $conditionalNode = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'conditional',
            'label' => 'Issue Detected?',
            'position_x' => 400,
            'position_y' => 350,
            'order' => 2,
        ]);

        $diagnoseNode = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'agent',
            'agent_id' => $diagnoseAgent->id,
            'label' => 'Diagnose Root Cause',
            'position_x' => 250,
            'position_y' => 500,
            'order' => 3,
            'config' => [
                'timeout' => 180,
                'retries' => 1,
                'prompt' => 'Analyze the detected issues from the previous step. Investigate root causes, trace error chains, and produce a diagnosis. Output: {"diagnosis": "...", "root_cause": "...", "recommended_action": "...", "safe_to_auto_remediate": true/false}',
            ],
        ]);

        $remediateNode = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'agent',
            'agent_id' => $remediateAgent->id,
            'label' => 'Remediate / Report',
            'position_x' => 250,
            'position_y' => 650,
            'order' => 4,
            'config' => [
                'timeout' => 180,
                'retries' => 0,
                'prompt' => 'Based on the diagnosis, take corrective action if tools allow it. If only read-only tools are available (watcher mode), generate a detailed incident report instead. Always verify the outcome and document what was done.',
            ],
        ]);

        $endNode = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'end',
            'label' => 'End',
            'position_x' => 400,
            'position_y' => 800,
            'order' => 5,
        ]);

        // Create edges
        // Start -> Detect
        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $startNode->id,
            'target_node_id' => $detectNode->id,
            'sort_order' => 0,
        ]);

        // Detect -> Conditional
        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $detectNode->id,
            'target_node_id' => $conditionalNode->id,
            'sort_order' => 0,
        ]);

        // Conditional -> Diagnose (if issues found with severity >= medium)
        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $conditionalNode->id,
            'target_node_id' => $diagnoseNode->id,
            'label' => 'Issues Found',
            'condition' => [
                'field' => 'output.issues_found',
                'operator' => '==',
                'value' => true,
            ],
            'sort_order' => 0,
        ]);

        // Conditional -> End (no issues, all clear)
        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $conditionalNode->id,
            'target_node_id' => $endNode->id,
            'label' => 'All Clear',
            'is_default' => true,
            'sort_order' => 1,
        ]);

        // Diagnose -> Remediate
        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $diagnoseNode->id,
            'target_node_id' => $remediateNode->id,
            'sort_order' => 0,
        ]);

        // Remediate -> End
        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $remediateNode->id,
            'target_node_id' => $endNode->id,
            'sort_order' => 0,
        ]);

        $this->command->info("Self-Healing Monitor workflow seeded (ID: {$workflow->id}).");
    }

    private function ensureAgent(Team $team, User $user, array $data): Agent
    {
        return Agent::updateOrCreate(
            ['team_id' => $team->id, 'name' => $data['name']],
            [
                'slug' => Str::slug($data['name']),
                'role' => $data['role'],
                'goal' => $data['goal'],
                'backstory' => $data['backstory'],
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-4-5-20250929',
                'status' => 'active',
                'config' => ['temperature' => 0.3, 'max_tokens' => 4096],
            ],
        );
    }
}
