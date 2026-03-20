<?php

namespace Database\Seeders;

use App\Domain\Agent\Models\Agent;
use App\Domain\Email\Models\EmailTemplate;
use App\Domain\Email\Models\EmailTheme;
use App\Domain\Marketplace\Enums\ListingVisibility;
use App\Domain\Marketplace\Enums\MarketplaceStatus;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Models\Skill;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PlatformMarketplaceSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $team = Team::withoutGlobalScopes()->where('slug', 'fleetq-platform')->first();

        if (! $team) {
            $this->command?->warn('Platform team not found. Run PlatformTeamSeeder first.');

            return;
        }

        $user = $team->owner;
        $count = 0;

        foreach ($this->definitions($team->id) as $def) {
            $listable = $this->resolveListable($def['type'], $def['listable_name'], $team->id);

            if (! $listable) {
                $this->command?->warn("Listable not found for listing: {$def['name']} (type={$def['type']}, name={$def['listable_name']})");

                continue;
            }

            $snapshot = $this->buildSnapshot($def['type'], $listable);

            MarketplaceListing::withoutGlobalScopes()->updateOrCreate(
                ['slug' => $def['slug']],
                [
                    'team_id' => $team->id,
                    'published_by' => $user->id,
                    'type' => $def['type'],
                    'listable_id' => $listable->id,
                    'name' => $def['name'],
                    'description' => $def['description'],
                    'readme' => $def['readme'] ?? null,
                    'category' => $def['category'],
                    'tags' => $def['tags'],
                    'status' => MarketplaceStatus::Published,
                    'visibility' => ListingVisibility::Public,
                    'is_official' => true,
                    'version' => '1.0.0',
                    'configuration_snapshot' => $snapshot,
                ],
            );

            $count++;
        }

        $this->command?->info("Platform marketplace listings seeded: {$count}");

        // Seed solution bundles
        $bundleCount = $this->seedBundles($team, $user);
        $this->command?->info("Platform solution bundles seeded: {$bundleCount}");
    }

    private function resolveListable(string $type, string $name, string $teamId): ?object
    {
        return match ($type) {
            'skill' => Skill::withoutGlobalScopes()->where('team_id', $teamId)->where('name', $name)->first(),
            'agent' => Agent::withoutGlobalScopes()->where('team_id', $teamId)->where('name', $name)->first(),
            'workflow' => Workflow::withoutGlobalScopes()->where('team_id', $teamId)->where('name', $name)->first(),
            'email_theme' => EmailTheme::withoutGlobalScopes()->where('team_id', $teamId)->where('name', $name)->first(),
            'email_template' => EmailTemplate::withoutGlobalScopes()->where('team_id', $teamId)->where('name', $name)->first(),
            default => null,
        };
    }

    private function buildSnapshot(string $type, object $item): array
    {
        return match ($type) {
            'skill' => [
                'type' => $item->type->value,
                'input_schema' => $item->input_schema,
                'output_schema' => $item->output_schema,
                'configuration' => $item->configuration,
                'system_prompt' => $item->system_prompt,
                'risk_level' => $item->risk_level->value,
            ],
            'agent' => [
                'role' => $item->role,
                'goal' => $item->goal,
                'backstory' => $item->backstory,
                'provider' => $item->provider,
                'model' => $item->model,
                'capabilities' => $item->capabilities ?? [],
                'constraints' => $item->constraints ?? [],
            ],
            'workflow' => $this->snapshotWorkflow($item),
            'email_theme' => [
                'logo_url' => $item->logo_url,
                'logo_width' => $item->logo_width,
                'background_color' => $item->background_color,
                'canvas_color' => $item->canvas_color,
                'primary_color' => $item->primary_color,
                'text_color' => $item->text_color,
                'heading_color' => $item->heading_color,
                'muted_color' => $item->muted_color,
                'divider_color' => $item->divider_color,
                'font_name' => $item->font_name,
                'font_url' => $item->font_url,
                'font_family' => $item->font_family,
                'heading_font_size' => $item->heading_font_size,
                'body_font_size' => $item->body_font_size,
                'line_height' => $item->line_height,
                'email_width' => $item->email_width,
                'content_padding' => $item->content_padding,
                'company_name' => $item->company_name,
                'company_address' => $item->company_address,
                'footer_text' => $item->footer_text,
            ],
            'email_template' => [
                'subject' => $item->subject,
                'preview_text' => $item->preview_text,
                'design_json' => $item->design_json,
                'html_cache' => $item->html_cache,
            ],
            default => [],
        };
    }

    private function snapshotWorkflow(Workflow $workflow): array
    {
        $nodes = $workflow->nodes()->get();
        $edges = $workflow->edges()->get();

        return [
            'description' => $workflow->description,
            'max_loop_iterations' => $workflow->max_loop_iterations,
            'estimated_cost_credits' => $workflow->estimated_cost_credits,
            'settings' => $workflow->settings,
            'nodes' => $nodes->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type->value,
                'label' => $n->label,
                'position_x' => $n->position_x,
                'position_y' => $n->position_y,
                'config' => $n->config,
                'order' => $n->order,
            ])->toArray(),
            'edges' => $edges->map(fn ($e) => [
                'id' => $e->id,
                'source_node_id' => $e->source_node_id,
                'target_node_id' => $e->target_node_id,
                'condition' => $e->condition,
                'label' => $e->label,
                'is_default' => $e->is_default,
                'sort_order' => $e->sort_order,
            ])->toArray(),
            'node_count' => $nodes->count(),
            'agent_node_count' => $nodes->filter(fn ($n) => $n->type->value === 'agent')->count(),
        ];
    }

    private function definitions(string $teamId): array
    {
        return [
            // ── Skills ─────────────────────────────────────────────────────────
            [
                'slug' => 'fleetq-skill-lead-qualifier',
                'type' => 'skill',
                'listable_name' => 'Lead Qualifier',
                'name' => 'Lead Qualifier',
                'description' => 'Score inbound leads against your ICP. Returns a 0–100 score, tier (hot/warm/cold), strengths, gaps, and recommended next action.',
                'category' => 'sales',
                'tags' => ['lead-scoring', 'sales', 'qualification', 'crm'],
            ],
            [
                'slug' => 'fleetq-skill-cold-email-writer',
                'type' => 'skill',
                'listable_name' => 'Cold Email Writer',
                'name' => 'Cold Email Writer',
                'description' => 'Generate personalized cold outreach emails with 3 subject line variants. Adapts tone to the prospect and goal. Under 120 words, no buzzwords.',
                'category' => 'sales',
                'tags' => ['email', 'outreach', 'copywriting', 'cold-email'],
            ],
            [
                'slug' => 'fleetq-skill-crm-note-summarizer',
                'type' => 'skill',
                'listable_name' => 'CRM Note Summarizer',
                'name' => 'CRM Note Summarizer',
                'description' => 'Compress raw sales call notes and email threads into structured CRM updates with next steps, risks, and a deal health score.',
                'category' => 'sales',
                'tags' => ['crm', 'sales', 'summarization', 'notes'],
            ],
            [
                'slug' => 'fleetq-skill-ticket-classifier',
                'type' => 'skill',
                'listable_name' => 'Support Ticket Classifier',
                'name' => 'Support Ticket Classifier',
                'description' => 'Classify support tickets by category (billing/technical/account), priority (P1–P4), urgency, and queue. Drafts an empathetic first response.',
                'category' => 'customer-support',
                'tags' => ['support', 'classification', 'routing', 'tickets'],
            ],
            [
                'slug' => 'fleetq-skill-sentiment-analyzer',
                'type' => 'skill',
                'listable_name' => 'Sentiment Analyzer',
                'name' => 'Sentiment Analyzer',
                'description' => 'Detect emotional tone, intent, and escalation risk in customer messages. Returns sentiment, emotions, intent, escalation risk, and key phrases.',
                'category' => 'customer-support',
                'tags' => ['sentiment', 'nlp', 'support', 'escalation'],
            ],
            [
                'slug' => 'fleetq-skill-knowledge-base-answerer',
                'type' => 'skill',
                'listable_name' => 'Knowledge Base Answerer',
                'name' => 'Knowledge Base Answerer',
                'description' => 'Answer support questions using provided KB articles. Returns a grounded answer with source citations and confidence score. Never guesses.',
                'category' => 'customer-support',
                'tags' => ['support', 'knowledge-base', 'rag', 'qa'],
            ],
            [
                'slug' => 'fleetq-skill-email-subject-generator',
                'type' => 'skill',
                'listable_name' => 'Email Subject Line Generator',
                'name' => 'Email Subject Line Generator',
                'description' => 'Generate 5 A/B-testable subject lines using different psychological triggers: curiosity, benefit, urgency, question, and personalization.',
                'category' => 'content',
                'tags' => ['email', 'subject-lines', 'copywriting', 'ab-testing'],
            ],
            [
                'slug' => 'fleetq-skill-social-media-adapter',
                'type' => 'skill',
                'listable_name' => 'Social Media Adapter',
                'name' => 'Social Media Adapter',
                'description' => 'Rewrite long-form content into platform-specific posts for Twitter/X, LinkedIn, and Instagram with appropriate tone, length, and hashtags.',
                'category' => 'content',
                'tags' => ['social-media', 'content', 'repurposing', 'linkedin', 'twitter'],
            ],
            [
                'slug' => 'fleetq-skill-meeting-notes-summarizer',
                'type' => 'skill',
                'listable_name' => 'Meeting Notes Summarizer',
                'name' => 'Meeting Notes Summarizer',
                'description' => 'Convert raw meeting notes or transcripts into structured summaries with decisions, action items (with owners and due dates), and key discussion points.',
                'category' => 'operations',
                'tags' => ['meetings', 'summarization', 'action-items', 'productivity'],
            ],
            [
                'slug' => 'fleetq-skill-document-extractor',
                'type' => 'skill',
                'listable_name' => 'Document Data Extractor',
                'name' => 'Document Data Extractor',
                'description' => 'Extract structured fields from unstructured documents (invoices, contracts, forms) against a target schema. Returns extracted data with confidence scores.',
                'category' => 'data',
                'tags' => ['document', 'extraction', 'data', 'invoices', 'ocr'],
            ],
            [
                'slug' => 'fleetq-skill-anomaly-explainer',
                'type' => 'skill',
                'listable_name' => 'Anomaly Explainer',
                'name' => 'Anomaly Explainer',
                'description' => 'Explain metric anomalies in plain language with ranked probable causes, investigation steps, and remediation suggestions.',
                'category' => 'data',
                'tags' => ['anomaly', 'monitoring', 'analytics', 'root-cause'],
            ],
            [
                'slug' => 'fleetq-skill-sla-monitor',
                'type' => 'skill',
                'listable_name' => 'SLA Monitor',
                'name' => 'SLA Monitor',
                'description' => 'Check SLA compliance status for open tickets or tasks. Returns breached items, at-risk items, compliance rate, and top priorities.',
                'category' => 'operations',
                'tags' => ['sla', 'monitoring', 'compliance', 'support'],
            ],
            [
                'slug' => 'fleetq-skill-data-schema-mapper',
                'type' => 'skill',
                'listable_name' => 'Data Schema Mapper',
                'name' => 'Data Schema Mapper',
                'description' => 'Map fields between two data schemas with transformation rules and compatibility scoring. Flags unmapped fields and type mismatch warnings.',
                'category' => 'data',
                'tags' => ['data-integration', 'schema', 'mapping', 'etl'],
            ],
            [
                'slug' => 'fleetq-skill-risk-assessor',
                'type' => 'skill',
                'listable_name' => 'Risk Assessor',
                'name' => 'Risk Assessor',
                'description' => 'Assess operational, financial, or technical risks from a description. Returns a risk matrix with likelihood, impact scores, and mitigation strategies.',
                'category' => 'operations',
                'tags' => ['risk', 'compliance', 'operations', 'assessment'],
            ],

            // ── Agents ─────────────────────────────────────────────────────────
            [
                'slug' => 'fleetq-agent-sdr',
                'type' => 'agent',
                'listable_name' => 'Sales Development Rep',
                'name' => 'Sales Development Rep',
                'description' => 'Qualifies leads against ICP, enriches prospect profiles, and crafts personalized cold outreach. Comes pre-wired with Lead Qualifier, Cold Email Writer, and CRM Note Summarizer skills.',
                'category' => 'sales',
                'tags' => ['sales', 'lead-generation', 'outreach', 'sdr'],
            ],
            [
                'slug' => 'fleetq-agent-support-triage',
                'type' => 'agent',
                'listable_name' => 'Support Triage Agent',
                'name' => 'Support Triage Agent',
                'description' => 'Classifies, prioritizes, and routes support tickets instantly. Detects sentiment and escalation risk. Drafts empathetic first responses. Pre-wired with Ticket Classifier, Sentiment Analyzer, and Knowledge Base Answerer.',
                'category' => 'customer-support',
                'tags' => ['support', 'triage', 'routing', 'automation'],
            ],
            [
                'slug' => 'fleetq-agent-email-marketer',
                'type' => 'agent',
                'listable_name' => 'Email Marketer',
                'name' => 'Email Marketer',
                'description' => 'Writes email campaigns, subject lines, and social media adaptations. Optimizes for opens and conversions. Pre-wired with Cold Email Writer, Email Subject Generator, and Social Media Adapter.',
                'category' => 'content',
                'tags' => ['email', 'marketing', 'copywriting', 'campaigns'],
            ],
            [
                'slug' => 'fleetq-agent-data-analyst',
                'type' => 'agent',
                'listable_name' => 'Data Analyst',
                'name' => 'Data Analyst',
                'description' => 'Extracts structured data from documents, maps schemas, and explains anomalies in plain language. Pre-wired with Document Extractor, Anomaly Explainer, and Data Schema Mapper.',
                'category' => 'data',
                'tags' => ['data', 'analytics', 'extraction', 'integration'],
            ],
            [
                'slug' => 'fleetq-agent-ops-coordinator',
                'type' => 'agent',
                'listable_name' => 'Operations Coordinator',
                'name' => 'Operations Coordinator',
                'description' => 'Tracks SLA compliance, summarizes meetings into action items, and assesses operational risks. Pre-wired with Meeting Notes Summarizer, SLA Monitor, and Risk Assessor.',
                'category' => 'operations',
                'tags' => ['operations', 'sla', 'meetings', 'risk'],
            ],
            [
                'slug' => 'fleetq-agent-code-reviewer',
                'type' => 'agent',
                'listable_name' => 'Code Review Agent',
                'name' => 'Code Review Agent',
                'description' => 'Reviews code for bugs, security vulnerabilities, performance issues, and quality problems. Returns findings by severity with fix suggestions. Pre-wired with Code Reviewer skill.',
                'category' => 'engineering',
                'tags' => ['code-review', 'engineering', 'security', 'quality'],
            ],
            [
                'slug' => 'fleetq-agent-research',
                'type' => 'agent',
                'listable_name' => 'Research Agent',
                'name' => 'Research Agent',
                'description' => 'Conducts systematic research by extracting data from documents and synthesizing findings into structured reports. Pre-wired with Meeting Notes Summarizer and Document Extractor.',
                'category' => 'research',
                'tags' => ['research', 'synthesis', 'intelligence', 'documents'],
            ],
            [
                'slug' => 'fleetq-agent-onboarding',
                'type' => 'agent',
                'listable_name' => 'Customer Onboarding Agent',
                'name' => 'Customer Onboarding Agent',
                'description' => 'Guides new customers through onboarding by answering questions, documenting kickoff calls, and creating success plans. Pre-wired with Meeting Notes Summarizer and Knowledge Base Answerer.',
                'category' => 'customer-support',
                'tags' => ['onboarding', 'customer-success', 'support'],
            ],
            [
                'slug' => 'fleetq-agent-escalation-analyst',
                'type' => 'agent',
                'listable_name' => 'Escalation Analyst',
                'name' => 'Escalation Analyst',
                'description' => 'Monitors tickets for escalation signals, checks SLA compliance, and prepares management briefings. Pre-wired with Sentiment Analyzer and SLA Monitor.',
                'category' => 'customer-support',
                'tags' => ['escalation', 'support', 'monitoring', 'management'],
            ],
            [
                'slug' => 'fleetq-agent-content-strategy',
                'type' => 'agent',
                'listable_name' => 'Content Strategy Agent',
                'name' => 'Content Strategy Agent',
                'description' => 'Repurposes content across channels, optimizes email subject lines, and synthesizes meeting outcomes. Pre-wired with Social Media Adapter, Email Subject Generator, and Meeting Notes Summarizer.',
                'category' => 'content',
                'tags' => ['content', 'strategy', 'social-media', 'email'],
            ],

            // ── Workflows ──────────────────────────────────────────────────────
            [
                'slug' => 'fleetq-workflow-lead-enrichment',
                'type' => 'workflow',
                'listable_name' => 'Lead Enrichment Pipeline',
                'name' => 'Lead Enrichment Pipeline',
                'description' => 'Automatically qualify, enrich, and score inbound leads. Routes high-scoring leads to outreach automatically. 7-node workflow with conditional routing.',
                'readme' => "# Lead Enrichment Pipeline\n\nThis workflow automates your lead qualification process end-to-end.\n\n## How it works\n1. **Qualify Lead** — Scores the lead against your ICP\n2. **Enrich Profile** — Researches company context\n3. **Score ≥ 70?** — Routes based on qualification score\n4. **Write Outreach** — Crafts personalized email for high-scoring leads\n5. **Update CRM** — Logs outcome\n\n## Setup\nAfter installing, open the workflow builder and assign your SDR Agent to the agent nodes.",
                'category' => 'sales',
                'tags' => ['lead-generation', 'automation', 'sales', 'crm', 'workflow'],
            ],
            [
                'slug' => 'fleetq-workflow-content-publishing',
                'type' => 'workflow',
                'listable_name' => 'Content Publishing Pipeline',
                'name' => 'Content Publishing Pipeline',
                'description' => 'Research, draft, adapt for multiple channels, and publish content — with a human approval gate. 7-node workflow for content teams.',
                'readme' => "# Content Publishing Pipeline\n\nAutomates the full content creation and distribution process.\n\n## How it works\n1. Research the topic\n2. Draft the full content piece\n3. Adapt for Twitter, LinkedIn, and email\n4. Human approval gate\n5. Generate email subject lines\n\n## Setup\nAssign your Content Strategy Agent and Research Agent to the relevant nodes.",
                'category' => 'content',
                'tags' => ['content', 'publishing', 'automation', 'workflow', 'approval'],
            ],
            [
                'slug' => 'fleetq-workflow-incident-response',
                'type' => 'workflow',
                'listable_name' => 'Incident Response Workflow',
                'name' => 'Incident Response Workflow',
                'description' => 'Detect, diagnose, and respond to operational incidents. Routes critical incidents through human approval before automated remediation. 8-node workflow.',
                'readme' => "# Incident Response Workflow\n\nAutomates your incident response process from detection to documentation.\n\n## How it works\n1. Explain the anomaly\n2. Assess risk level\n3. Critical incidents require human authorization\n4. Execute the response\n5. Document the incident\n\n## Setup\nAssign your Data Analyst and Operations Coordinator agents to the relevant nodes.",
                'category' => 'devops',
                'tags' => ['incident-response', 'devops', 'monitoring', 'automation', 'workflow'],
            ],
            [
                'slug' => 'fleetq-workflow-customer-onboarding',
                'type' => 'workflow',
                'listable_name' => 'Customer Onboarding Workflow',
                'name' => 'Customer Onboarding Workflow',
                'description' => 'Guide new customers through onboarding: document the kickoff, answer initial questions, and create a success plan with human review. 5-node workflow.',
                'category' => 'operations',
                'tags' => ['onboarding', 'customer-success', 'workflow', 'automation'],
            ],
            [
                'slug' => 'fleetq-workflow-support-escalation',
                'type' => 'workflow',
                'listable_name' => 'Support Escalation Workflow',
                'name' => 'Support Escalation Workflow',
                'description' => 'Analyze ticket batches for escalation signals, SLA compliance, and prepare management briefings automatically. 4-node workflow.',
                'category' => 'customer-support',
                'tags' => ['support', 'escalation', 'sla', 'workflow', 'automation'],
            ],
            [
                'slug' => 'fleetq-workflow-invoice-processing',
                'type' => 'workflow',
                'listable_name' => 'Invoice Processing Workflow',
                'name' => 'Invoice Processing Workflow',
                'description' => 'Extract invoice data, validate for anomalies, and route exceptions to human review. Clean invoices are approved automatically. 6-node workflow.',
                'category' => 'finance',
                'tags' => ['finance', 'invoices', 'automation', 'extraction', 'workflow'],
            ],

            // ── Email Themes ───────────────────────────────────────────────────
            [
                'slug' => 'fleetq-theme-clean-professional',
                'type' => 'email_theme',
                'listable_name' => 'Clean & Professional',
                'name' => 'Clean & Professional',
                'description' => 'A clean, professional email theme with blue primary color, Inter font, and a light gray background. Ideal for transactional and notification emails.',
                'category' => 'email',
                'tags' => ['email', 'theme', 'professional', 'blue'],
            ],
            [
                'slug' => 'fleetq-theme-dark-mode',
                'type' => 'email_theme',
                'listable_name' => 'Dark Mode',
                'name' => 'Dark Mode',
                'description' => 'A sleek dark mode email theme with indigo accents and light text. Perfect for developer tools, tech products, and modern SaaS.',
                'category' => 'email',
                'tags' => ['email', 'theme', 'dark', 'modern'],
            ],
            [
                'slug' => 'fleetq-theme-minimal',
                'type' => 'email_theme',
                'listable_name' => 'Minimal',
                'name' => 'Minimal',
                'description' => 'A typography-first minimal theme with tight spacing, Georgia serif font, and no visual clutter. Great for newsletters and long-form content.',
                'category' => 'email',
                'tags' => ['email', 'theme', 'minimal', 'typography'],
            ],
            [
                'slug' => 'fleetq-theme-brand-ready',
                'type' => 'email_theme',
                'listable_name' => 'Brand-Ready',
                'name' => 'Brand-Ready',
                'description' => 'A flexible branded theme with logo slot, green primary color, and generous spacing. Install and replace the logo and colors to match your brand.',
                'category' => 'email',
                'tags' => ['email', 'theme', 'branded', 'customizable'],
            ],

            // ── Email Templates ────────────────────────────────────────────────
            [
                'slug' => 'fleetq-tpl-welcome',
                'type' => 'email_template',
                'listable_name' => 'Welcome Email',
                'name' => 'Welcome Email',
                'description' => 'A warm welcome email for new signups with first-steps checklist and a primary CTA button. Uses {{user_name}} and {{company_name}} variables.',
                'category' => 'email',
                'tags' => ['email', 'template', 'welcome', 'onboarding', 'transactional'],
            ],
            [
                'slug' => 'fleetq-tpl-email-verify',
                'type' => 'email_template',
                'listable_name' => 'Email Verification',
                'name' => 'Email Verification',
                'description' => 'Email verification template with prominent CTA button and fallback URL. Uses {{verification_url}} variable.',
                'category' => 'email',
                'tags' => ['email', 'template', 'verification', 'auth', 'transactional'],
            ],
            [
                'slug' => 'fleetq-tpl-password-reset',
                'type' => 'email_template',
                'listable_name' => 'Password Reset',
                'name' => 'Password Reset',
                'description' => 'Password reset email with security messaging and 1-hour expiry notice. Uses {{reset_url}} variable.',
                'category' => 'email',
                'tags' => ['email', 'template', 'password', 'auth', 'transactional'],
            ],
            [
                'slug' => 'fleetq-tpl-trial-ending',
                'type' => 'email_template',
                'listable_name' => 'Trial Ending Reminder',
                'name' => 'Trial Ending Reminder',
                'description' => 'Trial expiry reminder with usage stats to create urgency. Uses {{days_remaining}}, {{trial_end_date}}, {{item_count}} variables.',
                'category' => 'email',
                'tags' => ['email', 'template', 'trial', 'conversion', 'saas'],
            ],
            [
                'slug' => 'fleetq-tpl-invoice-ready',
                'type' => 'email_template',
                'listable_name' => 'Invoice Ready',
                'name' => 'Invoice Ready',
                'description' => 'Invoice notification with invoice number, period, amount, and due date. Uses {{invoice_number}}, {{invoice_amount}}, {{invoice_due_date}} variables.',
                'category' => 'email',
                'tags' => ['email', 'template', 'invoice', 'billing', 'transactional'],
            ],
            [
                'slug' => 'fleetq-tpl-payment-failed',
                'type' => 'email_template',
                'listable_name' => 'Payment Failed',
                'name' => 'Payment Failed',
                'description' => 'Payment failure notification with clear CTA to update payment method and retry schedule. Uses {{amount}}, {{plan_name}}, {{attempt_date}} variables.',
                'category' => 'email',
                'tags' => ['email', 'template', 'payment', 'billing', 'dunning'],
            ],
            [
                'slug' => 'fleetq-tpl-weekly-digest',
                'type' => 'email_template',
                'listable_name' => 'Weekly Activity Digest',
                'name' => 'Weekly Activity Digest',
                'description' => 'Weekly workspace summary with experiment count, success rate, and credit usage stats. Uses {{week_start}}, {{experiments_run}}, {{success_rate}} variables.',
                'category' => 'email',
                'tags' => ['email', 'template', 'digest', 'weekly', 'engagement'],
            ],
            [
                'slug' => 'fleetq-tpl-alert-triggered',
                'type' => 'email_template',
                'listable_name' => 'Alert Triggered Notification',
                'name' => 'Alert Triggered Notification',
                'description' => 'Alert notification with severity callout, affected component, and details link. Uses {{alert_name}}, {{alert_severity}}, {{triggered_at}} variables.',
                'category' => 'email',
                'tags' => ['email', 'template', 'alert', 'notification', 'monitoring'],
            ],
            [
                'slug' => 'fleetq-tpl-approval-required',
                'type' => 'email_template',
                'listable_name' => 'Approval Required',
                'name' => 'Approval Required',
                'description' => 'Approval request notification with expiry and escalation contact. Uses {{approval_title}}, {{reviewer_name}}, {{expires_at}} variables.',
                'category' => 'email',
                'tags' => ['email', 'template', 'approval', 'workflow', 'notification'],
            ],
            [
                'slug' => 'fleetq-tpl-onboarding-day1',
                'type' => 'email_template',
                'listable_name' => 'Onboarding Day 1',
                'name' => 'Onboarding Day 1',
                'description' => 'Day 1 onboarding email with three key first steps and a setup CTA. Uses {{user_name}} variable.',
                'category' => 'email',
                'tags' => ['email', 'template', 'onboarding', 'day1', 'activation'],
            ],
            [
                'slug' => 'fleetq-tpl-reengagement',
                'type' => 'email_template',
                'listable_name' => 'Re-engagement Email',
                'name' => 'Re-engagement Email',
                'description' => 'Re-engagement email highlighting what\'s new for inactive users. Uses {{user_name}}, {{days_inactive}}, {{feature_1}}, {{feature_2}}, {{feature_3}} variables.',
                'category' => 'email',
                'tags' => ['email', 'template', 're-engagement', 'retention', 'winback'],
            ],
            [
                'slug' => 'fleetq-tpl-monthly-usage-report',
                'type' => 'email_template',
                'listable_name' => 'Monthly Usage Report',
                'name' => 'Monthly Usage Report',
                'description' => 'Monthly usage summary with experiments, success rate, active agents, and credit consumption. Uses {{month}}, {{total_experiments}}, {{credits_consumed}} variables.',
                'category' => 'email',
                'tags' => ['email', 'template', 'report', 'monthly', 'usage'],
            ],
        ];
    }

    private function seedBundles(Team $team, $user): int
    {
        $count = 0;

        foreach ($this->bundleDefinitions() as $def) {
            $items = [];
            $entityRefs = [];
            $skip = false;

            foreach ($def['components'] as $component) {
                $entity = $this->resolveListable($component['type'], $component['listable_name'], $team->id);
                if (! $entity) {
                    $this->command?->warn("Bundle component not found: {$component['listable_name']} (type={$component['type']})");
                    $skip = true;
                    break;
                }

                $items[] = [
                    'type' => $component['type'],
                    'ref_key' => $component['ref_key'],
                    'name' => $entity->name,
                    'description' => $entity->description ?? '',
                    'snapshot' => $this->buildSnapshot($component['type'], $entity),
                ];
            }

            if ($skip) {
                continue;
            }

            $snapshot = [
                'items' => $items,
                'entity_refs' => $def['entity_refs'] ?? [],
                'setup_hints' => $def['setup_hints'] ?? [],
                'required_credentials' => $def['required_credentials'] ?? [],
            ];

            MarketplaceListing::withoutGlobalScopes()->updateOrCreate(
                ['slug' => $def['slug']],
                [
                    'team_id' => $team->id,
                    'published_by' => $user->id,
                    'type' => 'bundle',
                    'listable_id' => null,
                    'name' => $def['name'],
                    'description' => $def['description'],
                    'readme' => $def['readme'] ?? null,
                    'category' => $def['category'],
                    'tags' => $def['tags'],
                    'status' => MarketplaceStatus::Published,
                    'visibility' => ListingVisibility::Public,
                    'is_official' => true,
                    'version' => '1.0.0',
                    'configuration_snapshot' => $snapshot,
                ],
            );

            $count++;
        }

        return $count;
    }

    private function bundleDefinitions(): array
    {
        return [
            // ── Customer Support Crew ────────────────────────────────────
            [
                'slug' => 'fleetq-bundle-customer-support-crew',
                'name' => 'Customer Support Crew',
                'description' => 'Complete customer support solution: triage agent classifies and routes tickets, escalation analyst monitors SLA breaches, and a workflow automates the escalation pipeline.',
                'readme' => "# Customer Support Crew\n\nA ready-to-use support automation bundle.\n\n## Included\n- **Support Triage Agent** — classifies, prioritizes, and drafts first responses\n- **Escalation Analyst** — monitors SLA breaches and prepares management briefings\n- **Support Escalation Workflow** — automates the triage → escalate pipeline\n\n## Setup\n1. Install the bundle\n2. Configure your ticket source (webhook or email connector)\n3. Customize agent system prompts for your product domain\n4. Activate the workflow",
                'category' => 'customer-support',
                'tags' => ['support', 'triage', 'escalation', 'sla', 'bundle', 'crew'],
                'components' => [
                    ['type' => 'agent', 'ref_key' => 'triage_agent', 'listable_name' => 'Support Triage Agent'],
                    ['type' => 'agent', 'ref_key' => 'escalation_agent', 'listable_name' => 'Escalation Analyst'],
                    ['type' => 'skill', 'ref_key' => 'ticket_classifier', 'listable_name' => 'Support Ticket Classifier'],
                    ['type' => 'skill', 'ref_key' => 'sentiment_analyzer', 'listable_name' => 'Sentiment Analyzer'],
                    ['type' => 'workflow', 'ref_key' => 'escalation_workflow', 'listable_name' => 'Support Escalation Workflow'],
                ],
                'entity_refs' => [
                    ['agent_ref' => 'triage_agent', 'skill_ref' => 'ticket_classifier'],
                    ['agent_ref' => 'triage_agent', 'skill_ref' => 'sentiment_analyzer'],
                    ['agent_ref' => 'escalation_agent', 'skill_ref' => 'sentiment_analyzer'],
                ],
                'setup_hints' => [
                    'Configure a webhook or email inbound connector to receive support tickets',
                    'Customize the Support Triage Agent system prompt with your product knowledge',
                    'Set SLA thresholds in the Escalation Analyst agent constraints',
                    'Activate the workflow after assigning agents to workflow nodes',
                ],
                'required_credentials' => [
                    ['type' => 'api_key', 'service' => 'helpdesk', 'purpose' => 'Ticket management (Zendesk, Freshdesk, or similar)'],
                ],
            ],

            // ── Lead Generation Pipeline ─────────────────────────────────
            [
                'slug' => 'fleetq-bundle-lead-generation-pipeline',
                'name' => 'Lead Generation Pipeline',
                'description' => 'End-to-end lead processing: SDR agent qualifies and writes personalized outreach, with a workflow that automates the qualify → enrich → outreach pipeline.',
                'readme' => "# Lead Generation Pipeline\n\nAutomate your inbound lead processing.\n\n## Included\n- **Sales Development Rep** — qualifies leads and crafts outreach\n- **Lead Qualifier** — scores leads against your ICP\n- **Cold Email Writer** — writes personalized cold emails\n- **Lead Enrichment Workflow** — automates the full pipeline\n\n## Setup\n1. Install the bundle\n2. Define your ICP criteria in the Lead Qualifier skill config\n3. Connect your CRM via webhook connector\n4. Customize email tone in the Cold Email Writer",
                'category' => 'sales',
                'tags' => ['sales', 'lead-generation', 'sdr', 'outreach', 'bundle', 'pipeline'],
                'components' => [
                    ['type' => 'agent', 'ref_key' => 'sdr_agent', 'listable_name' => 'Sales Development Rep'],
                    ['type' => 'skill', 'ref_key' => 'lead_qualifier', 'listable_name' => 'Lead Qualifier'],
                    ['type' => 'skill', 'ref_key' => 'cold_email_writer', 'listable_name' => 'Cold Email Writer'],
                    ['type' => 'workflow', 'ref_key' => 'enrichment_workflow', 'listable_name' => 'Lead Enrichment Pipeline'],
                ],
                'entity_refs' => [
                    ['agent_ref' => 'sdr_agent', 'skill_ref' => 'lead_qualifier'],
                    ['agent_ref' => 'sdr_agent', 'skill_ref' => 'cold_email_writer'],
                ],
                'setup_hints' => [
                    'Define your Ideal Customer Profile (ICP) in the Lead Qualifier skill configuration',
                    'Customize the Cold Email Writer tone and templates for your industry',
                    'Connect a CRM webhook to receive lead data',
                    'Assign the SDR Agent to workflow nodes after installation',
                ],
                'required_credentials' => [
                    ['type' => 'api_key', 'service' => 'crm', 'purpose' => 'CRM integration for lead sync (HubSpot, Salesforce, or similar)'],
                ],
            ],

            // ── Content Publishing Suite ─────────────────────────────────
            [
                'slug' => 'fleetq-bundle-content-publishing-suite',
                'name' => 'Content Publishing Suite',
                'description' => 'Full content creation and distribution: strategy agent, social media adaptation, email subject optimization, and a publishing workflow with human approval.',
                'readme' => "# Content Publishing Suite\n\nAutomate content creation and multi-channel distribution.\n\n## Included\n- **Content Strategy Agent** — plans and repurposes content\n- **Social Media Adapter** — adapts content for Twitter, LinkedIn, Instagram\n- **Email Subject Line Generator** — A/B testable subject lines\n- **Content Publishing Workflow** — research → draft → adapt → approve → publish\n\n## Setup\n1. Install the bundle\n2. Connect your social media accounts via outbound connectors\n3. Configure the human approval gate for your editorial team\n4. Customize tone and brand voice in the Content Strategy Agent",
                'category' => 'content',
                'tags' => ['content', 'publishing', 'social-media', 'email', 'bundle', 'suite'],
                'components' => [
                    ['type' => 'agent', 'ref_key' => 'content_agent', 'listable_name' => 'Content Strategy Agent'],
                    ['type' => 'skill', 'ref_key' => 'social_adapter', 'listable_name' => 'Social Media Adapter'],
                    ['type' => 'skill', 'ref_key' => 'subject_generator', 'listable_name' => 'Email Subject Line Generator'],
                    ['type' => 'workflow', 'ref_key' => 'publishing_workflow', 'listable_name' => 'Content Publishing Pipeline'],
                ],
                'entity_refs' => [
                    ['agent_ref' => 'content_agent', 'skill_ref' => 'social_adapter'],
                    ['agent_ref' => 'content_agent', 'skill_ref' => 'subject_generator'],
                ],
                'setup_hints' => [
                    'Configure outbound connectors for your social media channels',
                    'Set up the human approval gate with your editorial team',
                    'Customize brand voice and tone in the Content Strategy Agent',
                    'Connect email marketing platform for subject line testing',
                ],
                'required_credentials' => [],
            ],

            // ── Incident Response Kit ────────────────────────────────────
            [
                'slug' => 'fleetq-bundle-incident-response-kit',
                'name' => 'Incident Response Kit',
                'description' => 'Automated incident detection and response: ops coordinator diagnoses issues, anomaly explainer provides root cause analysis, and a workflow manages the response pipeline.',
                'readme' => "# Incident Response Kit\n\nAutomate your incident response from detection to documentation.\n\n## Included\n- **Operations Coordinator** — tracks SLA, summarizes meetings, assesses risk\n- **Anomaly Explainer** — explains metric anomalies with root cause analysis\n- **Risk Assessor** — evaluates risk with likelihood/impact scoring\n- **Incident Response Workflow** — detect → diagnose → approve → respond → document\n\n## Setup\n1. Install the bundle\n2. Connect monitoring alerts via webhook connector\n3. Configure risk thresholds in the Risk Assessor skill\n4. Set up Slack/email notifications for critical incidents",
                'category' => 'devops',
                'tags' => ['incident-response', 'devops', 'monitoring', 'anomaly', 'bundle', 'kit'],
                'components' => [
                    ['type' => 'agent', 'ref_key' => 'ops_agent', 'listable_name' => 'Operations Coordinator'],
                    ['type' => 'skill', 'ref_key' => 'anomaly_skill', 'listable_name' => 'Anomaly Explainer'],
                    ['type' => 'skill', 'ref_key' => 'risk_skill', 'listable_name' => 'Risk Assessor'],
                    ['type' => 'workflow', 'ref_key' => 'incident_workflow', 'listable_name' => 'Incident Response Workflow'],
                ],
                'entity_refs' => [
                    ['agent_ref' => 'ops_agent', 'skill_ref' => 'anomaly_skill'],
                    ['agent_ref' => 'ops_agent', 'skill_ref' => 'risk_skill'],
                ],
                'setup_hints' => [
                    'Connect monitoring alerts (PagerDuty, Datadog, etc.) via webhook connector',
                    'Configure risk thresholds in the Risk Assessor skill',
                    'Set up Slack or email outbound connectors for incident notifications',
                    'Assign the Operations Coordinator to workflow nodes after installation',
                ],
                'required_credentials' => [
                    ['type' => 'api_key', 'service' => 'monitoring', 'purpose' => 'Monitoring platform integration (PagerDuty, Datadog, or similar)'],
                ],
            ],
        ];
    }
}
