<?php

namespace Database\Seeders;

use App\Domain\Agent\Models\Agent;
use App\Domain\Project\Enums\ProjectExecutionMode;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Enums\ProjectType;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Enums\ExecutionType;
use App\Domain\Skill\Enums\RiskLevel;
use App\Domain\Skill\Enums\SkillStatus;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillVersion;
use App\Domain\Trigger\Enums\TriggerRuleStatus;
use App\Domain\Trigger\Models\TriggerRule;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowEdge;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Database\Seeder;

class EmailSupportPipelineSeeder extends Seeder
{
    public function run(): void
    {
        $team = Team::first();

        if (! $team) {
            $this->command?->warn('No team found. Run app:install first.');

            return;
        }

        $this->command?->info('Seeding email support pipeline skills...');
        $skills = $this->seedSkills($team);

        $this->command?->info('Seeding email support pipeline agents...');
        $agents = $this->seedAgents($team, $skills);

        $this->command?->info('Seeding email support pipeline workflow...');
        $workflow = $this->seedWorkflow($team, $agents);

        $this->command?->info('Seeding email support pipeline project & trigger rule...');
        $this->seedProjectAndTrigger($team, $workflow);

        $this->command?->info('Done: email support pipeline seeded.');
    }

    private function seedSkills(Team $team): array
    {
        $skills = [];

        foreach ($this->skillDefinitions() as $def) {
            $skill = Skill::withoutGlobalScopes()->updateOrCreate(
                ['team_id' => $team->id, 'slug' => $def['slug']],
                [
                    'name' => $def['name'],
                    'description' => $def['description'],
                    'type' => $def['type'],
                    'execution_type' => ExecutionType::Sync,
                    'status' => SkillStatus::Active,
                    'risk_level' => $def['risk_level'],
                    'input_schema' => $def['input_schema'],
                    'output_schema' => $def['output_schema'],
                    'configuration' => $def['configuration'],
                    'system_prompt' => $def['system_prompt'],
                    'requires_approval' => $def['risk_level']->requiresApproval(),
                    'current_version' => '1.0.0',
                ],
            );

            if ($skill->wasRecentlyCreated) {
                SkillVersion::create([
                    'skill_id' => $skill->id,
                    'version' => '1.0.0',
                    'input_schema' => $def['input_schema'],
                    'output_schema' => $def['output_schema'],
                    'configuration' => $def['configuration'],
                    'changelog' => 'Initial version — seeded by email support pipeline',
                ]);
            }

            $skills[$def['slug']] = $skill;
        }

        return $skills;
    }

    private function seedAgents(Team $team, array $skills): array
    {
        $agents = [];

        foreach ($this->agentDefinitions() as $def) {
            $pricing = config("llm_pricing.providers.{$def['provider']}.{$def['model']}", []);

            $agent = Agent::withoutGlobalScopes()->updateOrCreate(
                ['team_id' => $team->id, 'slug' => $def['slug']],
                [
                    'name' => $def['name'],
                    'role' => $def['role'],
                    'goal' => $def['goal'],
                    'backstory' => $def['backstory'],
                    'provider' => $def['provider'],
                    'model' => $def['model'],
                    'status' => 'active',
                    'config' => $def['config'] ?? [],
                    'capabilities' => $def['capabilities'] ?? [],
                    'constraints' => $def['constraints'] ?? [],
                    'cost_per_1k_input' => $pricing['input'] ?? 0,
                    'cost_per_1k_output' => $pricing['output'] ?? 0,
                ],
            );

            $syncData = [];
            foreach ($def['skills'] as $priority => $skillSlug) {
                if (isset($skills[$skillSlug])) {
                    $syncData[$skills[$skillSlug]->id] = ['priority' => $priority];
                }
            }
            $agent->skills()->sync($syncData);

            $agents[$def['slug']] = $agent;
        }

        return $agents;
    }

    private function seedWorkflow(Team $team, array $agents): Workflow
    {
        $workflow = Workflow::withoutGlobalScopes()->updateOrCreate(
            ['team_id' => $team->id, 'slug' => 'email-support-pipeline'],
            [
                'user_id' => $team->owner_id,
                'name' => 'Email Support Pipeline',
                'description' => 'Automated email support handler: ingest email signal → classify intent/urgency → generate draft reply → human approval → outbound delivery.',
                'status' => WorkflowStatus::Active,
                'version' => 1,
                'max_loop_iterations' => 1,
                'settings' => [
                    'outbound_channel' => 'email',
                    'slack_notify_high_urgency' => true,
                ],
            ],
        );

        if (! $workflow->wasRecentlyCreated) {
            return $workflow;
        }

        $classifierAgent = $agents['support-classifier'] ?? null;
        $drafterAgent = $agents['reply-drafter'] ?? null;

        $nodeDefs = [
            [
                'type' => 'start',
                'label' => 'Receive Email Signal',
                'position_x' => 0,
                'position_y' => 200,
                'config' => [
                    'source_type' => 'email',
                    'connector' => 'imap',
                    'description' => 'Triggered when an inbound email signal is ingested via the IMAP connector.',
                ],
            ],
            [
                'type' => 'agent',
                'label' => 'Classify Intent & Urgency',
                'position_x' => 250,
                'position_y' => 200,
                'agent_id' => $classifierAgent?->id,
                'config' => [
                    'task' => 'Analyze the inbound email subject and body. Classify by intent (bug_report, feature_request, billing, general_inquiry) and urgency (low, medium, high). Output structured classification JSON.',
                    'skill' => 'email-intent-classifier',
                    'confidence_threshold' => 0.7,
                ],
            ],
            [
                'type' => 'agent',
                'label' => 'Generate Draft Reply',
                'position_x' => 500,
                'position_y' => 200,
                'agent_id' => $drafterAgent?->id,
                'config' => [
                    'task' => 'Using the classification output from the previous step, generate a professional email reply draft. Include appropriate tone based on urgency and intent. Add FleetQ branding footer.',
                    'skill' => 'support-reply-generator',
                    'inputs_from' => 'previous_step',
                ],
            ],
            [
                'type' => 'human_task',
                'label' => 'Approve Reply Draft',
                'position_x' => 750,
                'position_y' => 200,
                'config' => [
                    'task' => 'Review the AI-generated email reply draft. Approve to send, reject to discard, or edit before approving.',
                    'form_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'decision' => [
                                'type' => 'string',
                                'enum' => ['approve', 'approve_with_edits', 'reject'],
                                'description' => 'Approval decision',
                            ],
                            'edited_reply' => [
                                'type' => 'string',
                                'description' => 'Edited reply body (if approve_with_edits)',
                            ],
                            'rejection_reason' => [
                                'type' => 'string',
                                'description' => 'Reason for rejection (if rejected)',
                            ],
                        ],
                        'required' => ['decision'],
                    ],
                    'sla_minutes' => 30,
                    'escalation_policy' => 'notify_team_lead',
                ],
            ],
            [
                'type' => 'end',
                'label' => 'Send Reply / Complete',
                'position_x' => 1000,
                'position_y' => 200,
                'config' => [
                    'outbound_channel' => 'email',
                    'description' => 'If approved, send the reply via SMTP email connector to the original sender. Log outcome.',
                ],
            ],
        ];

        $nodeMap = [];
        foreach ($nodeDefs as $index => $nodeDef) {
            $node = WorkflowNode::create([
                'workflow_id' => $workflow->id,
                'agent_id' => $nodeDef['agent_id'] ?? null,
                'skill_id' => null,
                'type' => $nodeDef['type'],
                'label' => $nodeDef['label'],
                'position_x' => $nodeDef['position_x'],
                'position_y' => $nodeDef['position_y'],
                'config' => $nodeDef['config'],
                'order' => $index,
            ]);
            $nodeMap[$index] = $node->id;
        }

        $edgeDefs = [
            ['source' => 0, 'target' => 1, 'label' => 'Email ingested'],
            ['source' => 1, 'target' => 2, 'label' => 'Classified'],
            ['source' => 2, 'target' => 3, 'label' => 'Draft ready'],
            ['source' => 3, 'target' => 4, 'label' => 'Approved', 'is_default' => true],
        ];

        foreach ($edgeDefs as $edgeDef) {
            WorkflowEdge::create([
                'workflow_id' => $workflow->id,
                'source_node_id' => $nodeMap[$edgeDef['source']],
                'target_node_id' => $nodeMap[$edgeDef['target']],
                'label' => $edgeDef['label'] ?? null,
                'condition' => $edgeDef['condition'] ?? null,
                'is_default' => $edgeDef['is_default'] ?? false,
                'sort_order' => 0,
            ]);
        }

        return $workflow;
    }

    private function seedProjectAndTrigger(Team $team, Workflow $workflow): void
    {
        $project = Project::withoutGlobalScopes()->updateOrCreate(
            ['team_id' => $team->id, 'title' => 'Email Support Pipeline'],
            [
                'user_id' => $team->owner_id,
                'description' => 'Continuous project wrapping the email support workflow. Automatically triggered by inbound IMAP email signals.',
                'type' => ProjectType::Continuous,
                'execution_mode' => ProjectExecutionMode::Autonomous,
                'status' => ProjectStatus::Active,
                'workflow_id' => $workflow->id,
                'goal' => 'Classify inbound support emails, draft context-aware replies, and route through human approval before sending.',
                'budget_config' => [
                    'daily_cap' => 500,
                    'monthly_cap' => 10000,
                ],
                'notification_config' => [
                    'on_failure' => true,
                    'on_budget_warning' => true,
                ],
                'delivery_config' => [
                    'outbound_channel' => 'email',
                ],
                'settings' => [
                    'auto_retry' => false,
                    'max_retries' => 0,
                ],
            ],
        );

        TriggerRule::withoutGlobalScopes()->updateOrCreate(
            ['team_id' => $team->id, 'name' => 'Email → Support Pipeline'],
            [
                'project_id' => $project->id,
                'source_type' => 'imap',
                'conditions' => [],
                'input_mapping' => [
                    'subject' => '{{ signal.payload.subject }}',
                    'body' => '{{ signal.payload.body }}',
                    'sender_email' => '{{ signal.payload.from }}',
                    'sender_name' => '{{ signal.payload.from_name }}',
                ],
                'cooldown_seconds' => 0,
                'max_concurrent' => 5,
                'status' => TriggerRuleStatus::Active,
            ],
        );
    }

    // ─── Skill Definitions ──────────────────────────────────────────

    private function skillDefinitions(): array
    {
        return [
            [
                'name' => 'Email Intent Classifier',
                'slug' => 'email-intent-classifier',
                'description' => 'Multi-label intent classification with confidence scores on email body and subject. Classifies support emails into intent categories (bug_report, feature_request, billing, general_inquiry) and urgency levels (low, medium, high).',
                'type' => SkillType::Llm,
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are an expert email support classifier for the FleetQ platform (fleetq.net). Your job is to analyze inbound support emails and produce a structured classification.

## Classification Taxonomy

### Intent Labels (select ONE primary, optionally ONE secondary):
- **bug_report**: The sender describes something broken, an error, unexpected behavior, a crash, or a regression. Look for error messages, stack traces, "doesn't work", "broken", "fails", "stopped working".
- **feature_request**: The sender asks for new functionality, improvements, or enhancements. Look for "would be nice", "can you add", "I wish", "please support", "it would help if".
- **billing**: The sender asks about invoices, charges, refunds, plan changes, pricing, payment methods, or subscription status. Look for monetary references, plan names, "charge", "invoice", "refund", "upgrade", "downgrade".
- **general_inquiry**: Questions about usage, documentation, how-to, onboarding, integration guidance, or anything that doesn't fit the above categories.

### Urgency Levels:
- **high**: Production down, data loss, security vulnerability, billing overcharge, service completely unusable. Anything blocking the sender's core business operations.
- **medium**: Degraded functionality, non-critical bug, billing question with upcoming deadline, feature needed for a scheduled launch.
- **low**: General questions, feature wishes, minor cosmetic issues, documentation requests, feedback without time pressure.

## Rules
1. Analyze BOTH the subject line and the email body.
2. If the email contains multiple intents, pick the most actionable one as primary and note the secondary.
3. Confidence must be between 0.0 and 1.0. Be honest — if the email is ambiguous, reflect that in a lower confidence score.
4. Extract 1-3 keyword tags that capture the specific topic (e.g., "imap-connector", "webhook-timeout", "crew-execution").
5. Write a one-sentence summary of the sender's core request.
6. If the sender mentions a specific feature, agent, or component name, include it in the tags.

Respond ONLY with valid JSON matching the output schema. No preamble, no explanation.
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'subject' => ['type' => 'string', 'description' => 'Email subject line'],
                        'body' => ['type' => 'string', 'description' => 'Email body text (plain text or stripped HTML)'],
                        'sender_email' => ['type' => 'string', 'description' => 'Sender email address for context'],
                        'metadata' => [
                            'type' => 'object',
                            'description' => 'Optional metadata (e.g., previous ticket history, account tier)',
                            'properties' => [
                                'account_tier' => ['type' => 'string', 'description' => 'Sender account tier if known'],
                                'previous_tickets' => ['type' => 'integer', 'description' => 'Number of previous support tickets'],
                            ],
                        ],
                    ],
                    'required' => ['subject', 'body'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'primary_intent' => [
                            'type' => 'string',
                            'enum' => ['bug_report', 'feature_request', 'billing', 'general_inquiry'],
                            'description' => 'Primary intent classification',
                        ],
                        'secondary_intent' => [
                            'type' => 'string',
                            'enum' => ['bug_report', 'feature_request', 'billing', 'general_inquiry', null],
                            'description' => 'Secondary intent if the email contains multiple topics',
                        ],
                        'confidence' => [
                            'type' => 'number',
                            'minimum' => 0.0,
                            'maximum' => 1.0,
                            'description' => 'Classification confidence score',
                        ],
                        'urgency' => [
                            'type' => 'string',
                            'enum' => ['low', 'medium', 'high'],
                            'description' => 'Urgency level',
                        ],
                        'urgency_reason' => [
                            'type' => 'string',
                            'description' => 'Brief explanation of why this urgency level was assigned',
                        ],
                        'summary' => [
                            'type' => 'string',
                            'description' => 'One-sentence summary of the sender core request',
                        ],
                        'tags' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Keyword tags capturing the specific topic',
                        ],
                    ],
                    'required' => ['primary_intent', 'confidence', 'urgency', 'summary', 'tags'],
                ],
                'configuration' => ['max_tokens' => 1024, 'temperature' => 0.1],
            ],
            [
                'name' => 'Support Reply Generator',
                'slug' => 'support-reply-generator',
                'description' => 'Generates professional support reply drafts given classified intent, urgency, and original message. Produces contextual, empathetic email responses tailored to the FleetQ platform.',
                'type' => SkillType::Llm,
                'risk_level' => RiskLevel::Medium,
                'system_prompt' => <<<'PROMPT'
You are a professional customer support specialist for FleetQ (fleetq.net), an AI agent orchestration platform.

## Your Task
Generate a draft email reply to a classified support request. The classification (intent, urgency, tags) is provided alongside the original email.

## Tone & Style
- Professional but warm — not robotic, not overly casual.
- Empathetic: acknowledge the sender's frustration or need before jumping to solutions.
- Concise: get to the point. Support emails should be scannable.
- Action-oriented: clearly state what happens next (investigation, fix timeline, link to docs, escalation).

## Reply Structure
1. **Greeting**: Address the sender by name if available, otherwise use a professional generic greeting.
2. **Acknowledgment**: Show you understand their issue in 1-2 sentences.
3. **Response Body**: Based on the intent:
   - **bug_report**: Confirm the bug, mention it's being investigated, ask for reproduction steps if missing, provide a workaround if possible.
   - **feature_request**: Thank them, explain how feature requests are prioritized, set expectations on timeline.
   - **billing**: Address the specific concern, provide clear next steps, reference relevant plan details.
   - **general_inquiry**: Answer the question directly, link to relevant documentation at docs.fleetq.net.
4. **Next Steps**: Clear statement of what happens next and expected timeline.
5. **Sign-off**: Professional closing with support team attribution.

## Rules
- NEVER make up features or pricing. If unsure, direct to docs.fleetq.net or indicate a team member will follow up.
- For high-urgency items, mention escalation to the engineering or billing team.
- Include a "Powered by FleetQ AI Agents — fleetq.net" footer.
- Output ONLY the email reply text. No JSON wrapping, no metadata.
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'original_subject' => ['type' => 'string', 'description' => 'Original email subject'],
                        'original_body' => ['type' => 'string', 'description' => 'Original email body'],
                        'sender_name' => ['type' => 'string', 'description' => 'Sender name if known'],
                        'sender_email' => ['type' => 'string', 'description' => 'Sender email address'],
                        'classification' => [
                            'type' => 'object',
                            'description' => 'Output from the email-intent-classifier skill',
                            'properties' => [
                                'primary_intent' => ['type' => 'string'],
                                'urgency' => ['type' => 'string'],
                                'summary' => ['type' => 'string'],
                                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                            'required' => ['primary_intent', 'urgency', 'summary'],
                        ],
                    ],
                    'required' => ['original_subject', 'original_body', 'classification'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'reply_subject' => ['type' => 'string', 'description' => 'Reply email subject line'],
                        'reply_body' => ['type' => 'string', 'description' => 'Full email reply body'],
                        'internal_notes' => ['type' => 'string', 'description' => 'Notes for the support team (not sent to customer)'],
                    ],
                    'required' => ['reply_subject', 'reply_body'],
                ],
                'configuration' => ['max_tokens' => 2048, 'temperature' => 0.4],
            ],
        ];
    }

    // ─── Agent Definitions ──────────────────────────────────────────

    private function agentDefinitions(): array
    {
        return [
            [
                'name' => 'Support Classifier',
                'slug' => 'support-classifier',
                'role' => 'Email Support Triage Specialist',
                'goal' => 'Accurately classify inbound support emails by intent and urgency to enable fast, automated routing and response generation.',
                'backstory' => 'A meticulous support triage specialist trained on thousands of customer interactions across SaaS platforms. Understands the nuances between a frustrated user reporting a bug and one requesting a feature. Prioritizes accuracy over speed — a misclassified high-urgency ticket is worse than a slightly delayed classification. Works as the first stage in the email support pipeline, feeding structured classifications to downstream reply generation agents.',
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-4-5',
                'capabilities' => ['email_classification', 'intent_detection', 'urgency_assessment', 'text_analysis'],
                'constraints' => ['requires_approval' => false, 'max_tokens_per_call' => 1024],
                'config' => [
                    'pipeline_role' => 'classifier',
                    'supported_intents' => ['bug_report', 'feature_request', 'billing', 'general_inquiry'],
                    'supported_urgency_levels' => ['low', 'medium', 'high'],
                    'confidence_threshold' => 0.7,
                ],
                'skills' => ['email-intent-classifier'],
            ],
            [
                'name' => 'Reply Drafter',
                'slug' => 'reply-drafter',
                'role' => 'Customer Support Reply Specialist',
                'goal' => 'Generate professional, empathetic, and accurate email reply drafts based on classified support requests, ready for human approval before sending.',
                'backstory' => 'An experienced customer support writer who has crafted thousands of support replies across SaaS platforms. Knows that a great support reply acknowledges the customer, provides clear next steps, and sets honest expectations. Never makes up features or pricing — when unsure, defers to documentation or escalates to a human. Works downstream from the Support Classifier agent, receiving structured classifications to inform reply tone and content.',
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-4-5',
                'capabilities' => ['email_drafting', 'customer_communication', 'support_writing', 'tone_adaptation'],
                'constraints' => ['requires_approval' => true, 'max_tokens_per_call' => 2048],
                'config' => [
                    'pipeline_role' => 'responder',
                    'approval_required' => true,
                    'footer' => 'Powered by FleetQ AI Agents — fleetq.net',
                ],
                'skills' => ['support-reply-generator'],
            ],
        ];
    }
}
