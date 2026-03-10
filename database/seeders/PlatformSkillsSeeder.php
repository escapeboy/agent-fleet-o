<?php

namespace Database\Seeders;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Enums\ExecutionType;
use App\Domain\Skill\Enums\RiskLevel;
use App\Domain\Skill\Enums\SkillStatus;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillVersion;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class PlatformSkillsSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $team = Team::withoutGlobalScopes()->where('slug', 'fleetq-platform')->first();

        if (! $team) {
            $this->command?->warn('Platform team not found. Run PlatformTeamSeeder first.');

            return;
        }

        $skills = collect();

        foreach ($this->definitions() as $def) {
            $skill = Skill::withoutGlobalScopes()->updateOrCreate(
                ['team_id' => $team->id, 'slug' => $def['slug']],
                [
                    'name' => $def['name'],
                    'description' => $def['description'],
                    'type' => SkillType::Llm,
                    'execution_type' => ExecutionType::Sync,
                    'status' => SkillStatus::Active,
                    'risk_level' => $def['risk_level'],
                    'input_schema' => $def['input_schema'],
                    'output_schema' => $def['output_schema'],
                    'configuration' => $def['configuration'],
                    'system_prompt' => $def['system_prompt'],
                    'requires_approval' => false,
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
                    'changelog' => 'Initial platform skill',
                ]);
            }

            $skills->put($def['slug'], $skill);
        }

        $this->command?->info("Platform skills seeded: {$skills->count()}");
    }

    private function definitions(): array
    {
        return [
            [
                'slug' => 'fleetq-lead-qualifier',
                'name' => 'Lead Qualifier',
                'description' => 'Score inbound leads against your ideal customer profile using firmographic and behavioral signals. Returns a 0–100 score, tier classification, and recommended next action.',
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are an expert sales analyst specializing in lead qualification.

Given lead data (company name, industry, size, role, behavioral signals), score the lead against an Ideal Customer Profile (ICP) and return a structured qualification report.

Scoring criteria:
- Firmographic fit (company size, industry, location): 0–30 points
- Role/persona fit (title, seniority, decision-making authority): 0–30 points
- Intent signals (recent activity, engagement, keywords): 0–25 points
- Timing indicators (budget cycles, hiring patterns, tech stack changes): 0–15 points

Output a JSON object with:
- score (0–100)
- tier: "hot" (80–100), "warm" (50–79), "lukewarm" (25–49), "cold" (0–24)
- strengths: array of positive signals
- gaps: array of disqualifying factors
- recommended_action: what to do next (e.g., "Immediate outreach", "Nurture 30 days", "Disqualify")
- confidence: low/medium/high

Be precise and data-driven. Only use the signals provided.
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'lead' => ['type' => 'object', 'description' => 'Lead data: name, company, title, industry, company_size, signals'],
                        'icp' => ['type' => 'object', 'description' => 'Ideal Customer Profile criteria'],
                    ],
                    'required' => ['lead'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'score' => ['type' => 'integer', 'description' => 'ICP fit score 0–100'],
                        'tier' => ['type' => 'string', 'description' => 'hot/warm/lukewarm/cold'],
                        'strengths' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'gaps' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'recommended_action' => ['type' => 'string'],
                        'confidence' => ['type' => 'string'],
                    ],
                    'required' => ['score', 'tier', 'recommended_action'],
                ],
                'configuration' => ['max_tokens' => 1024, 'temperature' => 0.2],
            ],

            [
                'slug' => 'fleetq-cold-email-writer',
                'name' => 'Cold Email Writer',
                'description' => 'Generate personalized cold outreach emails with 3 subject line variants and a follow-up suggestion. Adapts tone to the prospect and goal.',
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are an elite B2B copywriter specializing in cold email outreach that gets replies.

Write a personalized cold email for the given prospect. Follow these principles:
- First line must reference something specific about the prospect (company news, role, mutual connection, or pain point)
- Keep the email under 120 words in the body
- One clear ask (CTA) — not "let me know if you're interested"
- No buzzwords: avoid "synergies", "leverage", "solutions", "touch base"
- Sound like a human, not a template

Return:
- subject_lines: array of 3 variants (short, curiosity-driven)
- email_body: the email text (under 120 words)
- follow_up: a 5-day follow-up message (under 60 words)
- personalization_angle: the specific hook you used
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'prospect' => ['type' => 'object', 'description' => 'Prospect: name, company, title, recent_news, pain_points'],
                        'sender' => ['type' => 'object', 'description' => 'Sender: name, company, value_prop, product'],
                        'goal' => ['type' => 'string', 'description' => 'Email goal: book_demo, get_reply, share_resource'],
                    ],
                    'required' => ['prospect', 'sender'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'subject_lines' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'email_body' => ['type' => 'string'],
                        'follow_up' => ['type' => 'string'],
                        'personalization_angle' => ['type' => 'string'],
                    ],
                    'required' => ['subject_lines', 'email_body'],
                ],
                'configuration' => ['max_tokens' => 1024, 'temperature' => 0.7],
            ],

            [
                'slug' => 'fleetq-crm-note-summarizer',
                'name' => 'CRM Note Summarizer',
                'description' => 'Compress raw sales call notes and email threads into a structured CRM update with next steps, risks, and a deal health score.',
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are a CRM assistant that extracts actionable intelligence from messy sales notes.

Given raw notes from a sales call or email thread, produce a concise CRM update.

Return a JSON object with:
- summary: 2–3 sentence executive summary of where the deal stands
- next_steps: array of {action, owner, due_date (if mentioned)}
- risks: array of potential blockers or objections raised
- deal_stage_suggestion: e.g. "Discovery", "Technical Validation", "Negotiation", "Closed Won/Lost"
- health_score: 1–5 (1=at risk, 5=strong)
- key_stakeholders: array of people mentioned with roles
- follow_up_date: extracted or suggested date

Be concise. The summary must be under 60 words.
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'notes' => ['type' => 'string', 'description' => 'Raw call notes or email thread'],
                        'deal_stage' => ['type' => 'string', 'description' => 'Current deal stage (optional)'],
                        'company' => ['type' => 'string', 'description' => 'Prospect company name (optional)'],
                    ],
                    'required' => ['notes'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'summary' => ['type' => 'string'],
                        'next_steps' => ['type' => 'array'],
                        'risks' => ['type' => 'array'],
                        'deal_stage_suggestion' => ['type' => 'string'],
                        'health_score' => ['type' => 'integer'],
                        'key_stakeholders' => ['type' => 'array'],
                        'follow_up_date' => ['type' => 'string'],
                    ],
                    'required' => ['summary', 'next_steps'],
                ],
                'configuration' => ['max_tokens' => 1024, 'temperature' => 0.3],
            ],

            [
                'slug' => 'fleetq-ticket-classifier',
                'name' => 'Support Ticket Classifier',
                'description' => 'Classify incoming support tickets by category, priority, and urgency tier. Suggests the right queue and drafts an initial response.',
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are a customer support routing specialist.

Analyze incoming support tickets and classify them accurately.

Categories: billing, technical, account, feature_request, bug_report, security, general
Priority: P1 (critical, data loss/outage), P2 (high, core feature broken), P3 (medium, degraded), P4 (low, cosmetic/question)
Urgency: immediate (< 1hr), same_day (< 8hr), normal (< 48hr), low (< 1 week)

Return:
- category: main category
- sub_category: more specific label
- priority: P1–P4
- urgency: immediate/same_day/normal/low
- queue: team to route to (e.g., billing_team, engineering, account_management)
- draft_response: a 2–3 sentence empathetic acknowledgment to send to the customer
- escalate: boolean — should this skip the queue and go directly to a manager?
- sentiment: positive/neutral/frustrated/angry

Keep the draft_response professional, empathetic, and specific to the issue described.
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'ticket_text' => ['type' => 'string', 'description' => 'Full ticket text including subject and body'],
                        'customer_plan' => ['type' => 'string', 'description' => 'Customer plan tier: free/starter/pro/enterprise'],
                        'previous_tickets' => ['type' => 'integer', 'description' => 'Number of previous tickets from this customer'],
                    ],
                    'required' => ['ticket_text'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'category' => ['type' => 'string'],
                        'sub_category' => ['type' => 'string'],
                        'priority' => ['type' => 'string'],
                        'urgency' => ['type' => 'string'],
                        'queue' => ['type' => 'string'],
                        'draft_response' => ['type' => 'string'],
                        'escalate' => ['type' => 'boolean'],
                        'sentiment' => ['type' => 'string'],
                    ],
                    'required' => ['category', 'priority', 'queue', 'draft_response'],
                ],
                'configuration' => ['max_tokens' => 768, 'temperature' => 0.2],
            ],

            [
                'slug' => 'fleetq-sentiment-analyzer',
                'name' => 'Sentiment Analyzer',
                'description' => 'Analyze the emotional tone of customer messages and detect underlying intent, frustration signals, and escalation risk.',
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are a sentiment analysis expert trained on customer service communications.

Analyze the provided text for:
1. Overall sentiment: positive, neutral, negative, mixed
2. Emotion signals: frustrated, confused, urgent, satisfied, angry, disappointed, excited
3. Intent: asking_question, reporting_problem, requesting_refund, threatening_churn, praising, other
4. Escalation risk: low/medium/high
5. Key phrases: the 3–5 most emotionally significant phrases

Return structured JSON. Be precise — distinguish between "mildly frustrated" and "very angry".
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string', 'description' => 'Customer message or conversation snippet'],
                        'context' => ['type' => 'string', 'description' => 'Optional context about the customer or situation'],
                    ],
                    'required' => ['text'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'sentiment' => ['type' => 'string'],
                        'emotions' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'intent' => ['type' => 'string'],
                        'escalation_risk' => ['type' => 'string'],
                        'key_phrases' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'confidence' => ['type' => 'string'],
                    ],
                    'required' => ['sentiment', 'intent', 'escalation_risk'],
                ],
                'configuration' => ['max_tokens' => 512, 'temperature' => 0.1],
            ],

            [
                'slug' => 'fleetq-knowledge-base-answerer',
                'name' => 'Knowledge Base Answerer',
                'description' => 'Answer support questions using provided knowledge base articles. Returns a grounded answer with source citations and confidence score.',
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are a support specialist who answers customer questions using only the provided knowledge base articles.

Rules:
- Only use information from the provided KB articles
- If the answer is not in the articles, say so clearly — do not guess
- Cite your sources by article title or ID
- Keep the answer concise and actionable (under 150 words)
- Use bullet points for multi-step instructions
- If the question is partially answered, answer what you can and note what's missing

Return:
- answer: the response text
- sources: array of article titles/IDs used
- confidence: high/medium/low
- answer_found: boolean
- suggested_followup: a clarifying question to ask if confidence is low
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'question' => ['type' => 'string', 'description' => 'Customer question'],
                        'kb_articles' => ['type' => 'array', 'description' => 'Array of knowledge base articles with title and content'],
                    ],
                    'required' => ['question', 'kb_articles'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'answer' => ['type' => 'string'],
                        'sources' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'confidence' => ['type' => 'string'],
                        'answer_found' => ['type' => 'boolean'],
                        'suggested_followup' => ['type' => 'string'],
                    ],
                    'required' => ['answer', 'confidence', 'answer_found'],
                ],
                'configuration' => ['max_tokens' => 768, 'temperature' => 0.2],
            ],

            [
                'slug' => 'fleetq-email-subject-generator',
                'name' => 'Email Subject Line Generator',
                'description' => 'Generate 5 A/B-testable subject lines for a given email body and audience. Each variant is optimized for a different opening strategy.',
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are an email marketing expert specializing in subject lines that maximize open rates.

Given an email body and audience context, generate 5 subject line variants, each using a different psychological trigger:
1. Curiosity gap (creates intrigue, withholds key information)
2. Benefit-first (leads with the outcome the reader gets)
3. Urgency/scarcity (time or availability constraint)
4. Question (invites the reader to answer)
5. Personalization (uses context to make it feel 1:1)

For each variant, provide:
- subject: the subject line text (max 60 characters)
- strategy: which trigger is used
- preview_text: a complementary preheader (max 90 characters)
- predicted_open_rate_rationale: brief explanation of why this might work
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'email_body' => ['type' => 'string', 'description' => 'The full email body text'],
                        'audience' => ['type' => 'string', 'description' => 'Target audience description'],
                        'goal' => ['type' => 'string', 'description' => 'Email goal: announce, nurture, transactional, promotional'],
                        'brand_voice' => ['type' => 'string', 'description' => 'Brand tone: professional, casual, playful, urgent'],
                    ],
                    'required' => ['email_body'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'variants' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'subject' => ['type' => 'string'],
                                    'strategy' => ['type' => 'string'],
                                    'preview_text' => ['type' => 'string'],
                                    'predicted_open_rate_rationale' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                    'required' => ['variants'],
                ],
                'configuration' => ['max_tokens' => 1024, 'temperature' => 0.8],
            ],

            [
                'slug' => 'fleetq-social-media-adapter',
                'name' => 'Social Media Adapter',
                'description' => 'Rewrite long-form content into platform-specific posts for Twitter/X, LinkedIn, and Instagram with appropriate tone, length, and hashtags.',
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are a social media specialist who adapts content for different platforms.

Given source content, rewrite it for the specified platform following these rules:

Twitter/X: Max 280 characters. Punchy, use line breaks for readability. 2–3 relevant hashtags. Can be a thread if needed.
LinkedIn: Professional but not boring. 3–5 short paragraphs. Start with a hook. End with a question or CTA. 3–5 hashtags.
Instagram: Visual-first copy. Short sentences. Emojis to break up text. 5–10 hashtags. Include alt text for the image.

Return adapted posts for each requested platform. Do not use buzzwords or corporate speak.
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'source_content' => ['type' => 'string', 'description' => 'Long-form content to adapt'],
                        'platforms' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Target platforms: twitter, linkedin, instagram'],
                        'brand_voice' => ['type' => 'string', 'description' => 'Brand tone description'],
                        'key_message' => ['type' => 'string', 'description' => 'Core message to emphasize'],
                    ],
                    'required' => ['source_content', 'platforms'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'posts' => ['type' => 'object', 'description' => 'Map of platform to post content'],
                    ],
                    'required' => ['posts'],
                ],
                'configuration' => ['max_tokens' => 1024, 'temperature' => 0.7],
            ],

            [
                'slug' => 'fleetq-meeting-notes-summarizer',
                'name' => 'Meeting Notes Summarizer',
                'description' => 'Convert raw meeting transcripts or notes into structured summaries with decisions, action items, and key discussion points.',
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are an expert meeting facilitator who extracts actionable intelligence from meeting notes.

Produce a structured summary that includes:
1. Meeting purpose and context (1 sentence)
2. Key decisions made (bulleted list)
3. Action items — each with: action, owner (if mentioned), due date (if mentioned), priority
4. Key discussion points and concerns raised
5. Parking lot items (topics raised but deferred)
6. Next meeting agenda suggestions (if applicable)

Be concise. Action items must be specific and actionable, not vague ("team to decide" is not acceptable — who decides?).
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'notes' => ['type' => 'string', 'description' => 'Raw meeting notes or transcript'],
                        'meeting_type' => ['type' => 'string', 'description' => 'Type of meeting: standup, planning, review, retrospective, kickoff, other'],
                        'attendees' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'List of attendee names'],
                    ],
                    'required' => ['notes'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'purpose' => ['type' => 'string'],
                        'decisions' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'action_items' => ['type' => 'array'],
                        'discussion_points' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'parking_lot' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'next_agenda' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['decisions', 'action_items'],
                ],
                'configuration' => ['max_tokens' => 2048, 'temperature' => 0.3],
            ],

            [
                'slug' => 'fleetq-document-extractor',
                'name' => 'Document Data Extractor',
                'description' => 'Extract structured data fields from unstructured documents such as invoices, contracts, and forms. Returns extracted fields with confidence scores.',
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are a document intelligence specialist who extracts structured data from unstructured text.

Given document text and a target schema, extract the requested fields as accurately as possible.

For each field:
- Extract the value exactly as it appears in the document
- Normalize formats where specified (e.g., dates to ISO 8601, amounts to numbers)
- Assign a confidence score: high (clearly stated), medium (inferred), low (uncertain)
- If a field is not found, set value to null and confidence to "not_found"

Return the extracted fields as a JSON object matching the target schema, plus a metadata object with overall extraction quality and any warnings.
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'document_text' => ['type' => 'string', 'description' => 'The full document text'],
                        'target_schema' => ['type' => 'object', 'description' => 'Fields to extract with descriptions'],
                        'document_type' => ['type' => 'string', 'description' => 'Document type: invoice, contract, form, receipt, report'],
                    ],
                    'required' => ['document_text', 'target_schema'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'extracted' => ['type' => 'object', 'description' => 'Extracted field values'],
                        'confidence' => ['type' => 'object', 'description' => 'Confidence per field'],
                        'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'overall_quality' => ['type' => 'string'],
                    ],
                    'required' => ['extracted', 'confidence'],
                ],
                'configuration' => ['max_tokens' => 2048, 'temperature' => 0.1],
            ],

            [
                'slug' => 'fleetq-anomaly-explainer',
                'name' => 'Anomaly Explainer',
                'description' => 'Identify anomalies in metric series and explain probable causes in plain language with investigation recommendations.',
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are a data analyst specializing in anomaly detection and root cause analysis.

Given a series of metrics with an identified anomaly, explain what happened in plain, non-technical language that a business stakeholder can understand.

Provide:
1. Plain-language description of the anomaly (what changed, by how much, compared to what baseline)
2. Probable causes ranked by likelihood (use domain knowledge about what typically causes such anomalies)
3. What to investigate first — specific data points, systems, or events to check
4. Whether the anomaly is likely transient (one-off) or persistent (ongoing trend)
5. Suggested remediation steps if applicable

Always caveat your conclusions with the available data and acknowledge uncertainty where it exists.
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'metric_name' => ['type' => 'string', 'description' => 'Name of the metric'],
                        'metric_series' => ['type' => 'array', 'description' => 'Array of {timestamp, value} data points'],
                        'anomaly_point' => ['type' => 'object', 'description' => 'The specific anomalous {timestamp, value}'],
                        'context' => ['type' => 'string', 'description' => 'Additional context about the system or recent changes'],
                    ],
                    'required' => ['metric_name', 'metric_series', 'anomaly_point'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'description' => ['type' => 'string'],
                        'probable_causes' => ['type' => 'array'],
                        'investigation_steps' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'anomaly_type' => ['type' => 'string'],
                        'remediation' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['description', 'probable_causes', 'investigation_steps'],
                ],
                'configuration' => ['max_tokens' => 1024, 'temperature' => 0.3],
            ],

            [
                'slug' => 'fleetq-sla-monitor',
                'name' => 'SLA Monitor',
                'description' => 'Analyze open tasks or tickets against SLA deadlines and generate a compliance status report with at-risk items and breach alerts.',
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are an SLA compliance analyst.

Given a list of open items with creation timestamps and SLA rules, calculate their SLA status and produce a compliance report.

For each item, determine:
- sla_deadline: calculated from creation_date + sla_hours
- time_remaining: hours until breach (negative = already breached)
- status: "on_track", "at_risk" (< 20% time left), "breached"

Return a summary report with:
- overall_compliance_rate: percentage of items on track
- breached: array of item IDs that have exceeded SLA
- at_risk: array of item IDs with < 20% of SLA time remaining
- on_track: count of items meeting SLA
- recommended_priorities: top 5 items to address immediately, with reasoning
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'items' => ['type' => 'array', 'description' => 'Array of items with id, title, created_at, priority'],
                        'sla_rules' => ['type' => 'object', 'description' => 'SLA hours by priority: {P1: 1, P2: 4, P3: 24, P4: 72}'],
                        'current_time' => ['type' => 'string', 'description' => 'Current timestamp ISO 8601'],
                    ],
                    'required' => ['items', 'sla_rules'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'overall_compliance_rate' => ['type' => 'number'],
                        'breached' => ['type' => 'array'],
                        'at_risk' => ['type' => 'array'],
                        'on_track' => ['type' => 'integer'],
                        'recommended_priorities' => ['type' => 'array'],
                    ],
                    'required' => ['overall_compliance_rate', 'breached', 'at_risk'],
                ],
                'configuration' => ['max_tokens' => 1024, 'temperature' => 0.1],
            ],

            [
                'slug' => 'fleetq-data-schema-mapper',
                'name' => 'Data Schema Mapper',
                'description' => 'Map fields between two data schemas (e.g., CRM to ERP) with transformation rules and validation warnings.',
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are a data integration specialist who maps fields between different data schemas.

Given a source schema and target schema (with optional sample records), produce a field mapping specification.

For each target field:
1. Identify the matching source field(s)
2. Specify any transformation needed (type conversion, concatenation, formatting)
3. Flag potential data loss, truncation, or type mismatch warnings
4. Mark confidence: exact_match, likely_match, inferred, not_found

Return:
- mappings: array of {source_field, target_field, transformation, confidence, warning}
- unmapped_target_fields: target fields with no source match
- unmapped_source_fields: source fields not used in any mapping
- compatibility_score: 0–100 (% of target fields that can be mapped)
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'source_schema' => ['type' => 'object', 'description' => 'Source schema fields with types'],
                        'target_schema' => ['type' => 'object', 'description' => 'Target schema fields with types'],
                        'sample_record' => ['type' => 'object', 'description' => 'Optional sample record from source'],
                    ],
                    'required' => ['source_schema', 'target_schema'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'mappings' => ['type' => 'array'],
                        'unmapped_target_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'unmapped_source_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'compatibility_score' => ['type' => 'integer'],
                    ],
                    'required' => ['mappings', 'compatibility_score'],
                ],
                'configuration' => ['max_tokens' => 2048, 'temperature' => 0.2],
            ],

            [
                'slug' => 'fleetq-risk-assessor',
                'name' => 'Risk Assessor',
                'description' => 'Assess operational, financial, or technical risks from a description. Returns a risk matrix with likelihood, impact, and mitigation strategies.',
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are a risk management expert who assesses and prioritizes risks.

Given a description of a situation, plan, or system, identify and assess all material risks.

For each risk:
- risk_id: sequential identifier
- category: operational/financial/technical/reputational/compliance/security
- description: clear statement of what could go wrong
- likelihood: 1–5 (1=rare, 5=almost certain)
- impact: 1–5 (1=minimal, 5=catastrophic)
- risk_score: likelihood × impact (1–25)
- current_controls: any existing mitigations already in place
- mitigation_strategies: 2–3 recommended actions to reduce this risk
- residual_risk: expected risk_score after mitigation

Return risks sorted by risk_score descending. Identify the top 3 as "critical risks".
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'description' => ['type' => 'string', 'description' => 'Description of the situation, plan, or system to assess'],
                        'risk_appetite' => ['type' => 'string', 'description' => 'Organization risk appetite: low/medium/high'],
                        'existing_controls' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Known existing controls'],
                    ],
                    'required' => ['description'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'risks' => ['type' => 'array'],
                        'critical_risks' => ['type' => 'array'],
                        'overall_risk_level' => ['type' => 'string'],
                        'summary' => ['type' => 'string'],
                    ],
                    'required' => ['risks', 'overall_risk_level'],
                ],
                'configuration' => ['max_tokens' => 2048, 'temperature' => 0.3],
            ],
        ];
    }
}
