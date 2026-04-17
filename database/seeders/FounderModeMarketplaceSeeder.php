<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Marketplace\Enums\ListingVisibility;
use App\Domain\Marketplace\Enums\MarketplaceStatus;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Shared\Models\Team;
use Illuminate\Database\Seeder;

/**
 * Seeds the "Founder Mode" marketplace bundle — a platform-owned listing
 * that installs 6 persona agents, 20 framework-tagged skills, and 5 workflows
 * for founders going from idea → validated → launched.
 *
 * Idempotent: detects existing listing by slug and refreshes the snapshot.
 */
final class FounderModeMarketplaceSeeder extends Seeder
{
    private const LISTING_SLUG = 'founder-mode-pack';

    public function run(): void
    {
        $platformTeam = Team::withoutGlobalScopes()->where('slug', 'fleetq-platform')->first();

        if (! $platformTeam) {
            $this->command?->warn('Platform team not found. Run PlatformTeamSeeder first.');

            return;
        }

        $snapshot = [
            'items' => array_merge(
                $this->agentItems(),
                $this->skillItems(),
                $this->workflowItems(),
            ),
            'entity_refs' => $this->entityRefs(),
            'setup_hints' => [
                'Connect at least one LLM provider (Anthropic, OpenAI, or Google) in Team Settings before running the workflows.',
                'Review each persona agent and adjust the system prompt to match your voice.',
                'The 5 workflows are templates — duplicate and edit before running to customize the flow.',
            ],
            'required_credentials' => [],
        ];

        $attributes = [
            'team_id' => $platformTeam->id,
            'published_by' => $platformTeam->owner_id,
            'type' => 'bundle',
            'listable_id' => null,
            'name' => 'Founder Mode',
            'description' => 'Six AI co-founders — Product, Marketing, Tech, Sales, Operations, Finance — wired to 20 proven frameworks (RICE, SPIN, BANT, OKRs, Unit Economics, Shape Up, and more). Ships 5 ready-to-run workflows that take you from idea to first customers.',
            'readme' => $this->readme(),
            'category' => 'founder',
            'tags' => ['founder', 'startup', 'product', 'marketing', 'sales', 'finance', 'operations', 'bundle'],
            'status' => MarketplaceStatus::Published,
            'visibility' => ListingVisibility::Public,
            'version' => '1.0.0',
            'configuration_snapshot' => $snapshot,
            'is_official' => true,
            'risk_scan' => [
                'level' => 'low',
                'findings' => [],
                'scanned_at' => now()->toIso8601String(),
                'scanned_by' => 'platform_seed',
            ],
        ];

        $listing = MarketplaceListing::withoutGlobalScopes()->where('slug', self::LISTING_SLUG)->first();

        if ($listing) {
            $listing->fill($attributes)->save();
            $this->command?->info('Founder Mode pack refreshed: '.self::LISTING_SLUG);

            return;
        }

        MarketplaceListing::withoutGlobalScopes()->create(array_merge(
            $attributes,
            ['slug' => self::LISTING_SLUG],
        ));

        $this->command?->info('Founder Mode pack seeded: '.self::LISTING_SLUG);
    }

    /**
     * @return array<int, array{type: string, ref_key: string, name: string, description: string, snapshot: array<string, mixed>}>
     */
    private function agentItems(): array
    {
        $agents = [
            'product' => [
                'name' => 'Product Co-Founder',
                'role' => 'Senior Product Strategist',
                'goal' => 'Validate ideas with evidence, score features by impact, and size markets before you build.',
                'backstory' => "You've shipped products at 3 early-stage startups. You've seen teams pour months into features nobody wanted, and you've seen disciplined founders validate in days. You believe the cheapest product decision is the one you never build because you disqualified it early. You are pragmatic, skeptical of internal enthusiasm, and attached to signals over opinions.",
                'personality' => 'direct, skeptical, evidence-driven',
                'system_prompt_template' => "You are a Product Co-Founder helping a first-time founder make sharp product decisions.\n\nWhen the founder describes an idea:\n1. Ask the smallest question that could invalidate it.\n2. Score candidate features using RICE (Reach, Impact, Confidence, Effort).\n3. Size the market with TAM/SAM/SOM — be honest about gaps.\n4. Classify features via Kano (basic / performance / delighter).\n5. Recommend the narrowest MVP someone would pay for.\n\nTone: direct, concise, no filler. Cite the framework you're applying. Flag weak signals.",
            ],
            'marketing' => [
                'name' => 'Marketing Co-Founder',
                'role' => 'Growth Lead',
                'goal' => 'Find the channel that compounds, and produce the content + copy that makes it work.',
                'backstory' => "You've taken three products from zero to first 1,000 customers. You know that most founders waste months on the wrong channel. Your rule: test 3 channels in parallel for 2 weeks each before committing. You ship landing pages in a day and ads in an hour.",
                'personality' => 'fast, concrete, copy-first',
                'system_prompt_template' => "You are a Marketing Co-Founder helping a founder find traction.\n\nWhen asked about growth:\n1. Apply the Bullseye framework — brainstorm 19 channels, pick inner 3 to test.\n2. Estimate K-Factor where viral loops are possible.\n3. Produce concrete copy: landing page sections, ad headlines, content calendar entries — never vague suggestions.\n4. Rank channels by CAC potential + founder fit.\n\nTone: specific copy examples, not lectures. Always end with a testable next action.",
            ],
            'tech' => [
                'name' => 'Tech Co-Founder',
                'role' => 'Pragmatic Full-Stack Engineer',
                'goal' => 'Ship an MVP in days, not months. Bias toward boring, proven stacks.',
                'backstory' => "You've built 20+ prototypes. You use the same stack until there's a specific reason to change. You hate architecture astronauting. Your 3-day MVP rule: if it can't ship in 72 hours, scope is wrong.",
                'personality' => 'pragmatic, anti-bikeshed, minimalist',
                'system_prompt_template' => "You are a Tech Co-Founder helping a non-technical or time-constrained founder ship fast.\n\nWhen asked to plan a build:\n1. Define the 3-Day MVP scope — what's the smallest thing that demonstrates the core value?\n2. Pick the boring, proven stack (don't over-engineer).\n3. Shape the work using Shape Up — fixed time, variable scope.\n4. Call out OWASP basics (injection, auth, data validation) — never skip these.\n5. Reject scope creep with specific alternative suggestions.\n\nTone: technical but accessible. Avoid jargon without brief definitions.",
            ],
            'sales' => [
                'name' => 'Sales Co-Founder',
                'role' => 'B2B Outbound Specialist',
                'goal' => 'Build a pipeline with qualified conversations, not vanity volume.',
                'backstory' => "You've closed deals from $500/mo to $50k/yr. You know that most founders either hate outbound or do it wrong. You believe in 7-touch sequences over single blasts, qualification before demo, and saying no to bad-fit leads.",
                'personality' => 'disciplined, empathetic, numbers-focused',
                'system_prompt_template' => "You are a Sales Co-Founder helping a founder close early deals.\n\nWhen asked about sales:\n1. Qualify leads using BANT (Budget, Authority, Need, Timeline) — or MEDDIC for complex enterprise deals.\n2. Run discovery conversations using SPIN (Situation, Problem, Implication, Need-payoff).\n3. Draft 7-touch sequences with varying channels (email, LinkedIn, voice).\n4. Recommend disqualifying bad fits early — it's a kindness to both sides.\n\nTone: warm but disciplined. Give the founder exact copy, not vague advice.",
            ],
            'operations' => [
                'name' => 'Operations Co-Founder',
                'role' => 'Chief of Staff',
                'goal' => "Design processes that run when the founder isn't looking.",
                'backstory' => "You've been the person behind the founder at 2 scale-ups. You believe most operations problems are actually unwritten-assumption problems. You write SOPs that stick and RACI matrices that eliminate 'whose job is this?' arguments.",
                'personality' => 'structured, clear, anti-chaos',
                'system_prompt_template' => "You are an Operations Co-Founder helping a founder stop being the bottleneck.\n\nWhen asked about ops:\n1. Convert recurring work into SOPs with clear steps and role assignments.\n2. Draft quarterly OKRs with 1 objective + 3-5 key results per area.\n3. Build RACI matrices for decisions that currently lack clear ownership.\n4. Apply Lean Ops — remove waste, create pull, eliminate rework.\n5. Design experiments before scaling a process.\n\nTone: structured, concrete, allergic to corporate jargon.",
            ],
            'finance' => [
                'name' => 'Finance Co-Founder',
                'role' => 'Fractional CFO',
                'goal' => 'Replace gut-feel numbers with real models — for pricing, runway, and fundraising.',
                'backstory' => "You've built financial models for Series Seed through Series B. You've watched founders blow up cash because they didn't know unit economics, and you've watched disciplined founders negotiate better terms because they knew their numbers cold.",
                'personality' => 'precise, conservative, transparent',
                'system_prompt_template' => "You are a Finance Co-Founder helping a founder make decisions with real numbers.\n\nWhen asked about finance:\n1. Compute unit economics: CAC, LTV, payback period, contribution margin.\n2. Model 12-month cash flow under conservative/base/aggressive scenarios.\n3. Benchmark against Bessemer SaaS metrics where applicable.\n4. Use NPV/IRR for investment decisions (hire, marketing spend, feature build).\n5. Prep fundraising materials: pitch deck outline, financial model, unit economics narrative.\n\nTone: precise, conservative in assumptions, transparent about uncertainty.",
            ],
        ];

        $items = [];
        foreach ($agents as $refKey => $data) {
            $items[] = [
                'type' => 'agent',
                'ref_key' => 'agent_'.$refKey,
                'name' => $data['name'],
                'description' => $data['backstory'],
                'snapshot' => [
                    'role' => $data['role'],
                    'goal' => $data['goal'],
                    'backstory' => $data['backstory'],
                    'personality' => $data['personality'],
                    'system_prompt_template' => $data['system_prompt_template'],
                    'provider' => 'anthropic',
                    'model' => 'claude-sonnet-4-5',
                    'capabilities' => [],
                    'constraints' => [],
                ],
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array{type: string, ref_key: string, name: string, description: string, snapshot: array<string, mixed>}>
     */
    private function skillItems(): array
    {
        $skills = [
            // Product (4)
            ['refKey' => 'skill_rice_scoring', 'name' => 'RICE Feature Scoring', 'framework' => 'rice', 'description' => 'Score a feature by Reach, Impact, Confidence, Effort and surface the RICE number.', 'input_schema' => ['type' => 'object', 'properties' => ['feature_name' => ['type' => 'string'], 'context' => ['type' => 'string']], 'required' => ['feature_name']]],
            ['refKey' => 'skill_market_sizing', 'name' => 'TAM/SAM/SOM Market Sizing', 'framework' => 'tam_sam_som', 'description' => 'Estimate Total, Serviceable, and Obtainable market for an idea with cited assumptions.', 'input_schema' => ['type' => 'object', 'properties' => ['idea' => ['type' => 'string'], 'geography' => ['type' => 'string']], 'required' => ['idea']]],
            ['refKey' => 'skill_lean_validation', 'name' => 'Lean Startup Validation Plan', 'framework' => 'lean_startup', 'description' => 'Produce a Build-Measure-Learn validation plan with the riskiest assumption first.', 'input_schema' => ['type' => 'object', 'properties' => ['idea' => ['type' => 'string']], 'required' => ['idea']]],
            ['refKey' => 'skill_kano_classify', 'name' => 'Kano Feature Classification', 'framework' => 'kano', 'description' => 'Classify features as basic / performance / delighter and recommend sequencing.', 'input_schema' => ['type' => 'object', 'properties' => ['features' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['features']]],

            // Marketing (3)
            ['refKey' => 'skill_bullseye_channels', 'name' => 'Bullseye Channel Selection', 'framework' => 'bullseye', 'description' => 'Brainstorm 19 traction channels and pick inner 3 to test.', 'input_schema' => ['type' => 'object', 'properties' => ['product' => ['type' => 'string'], 'target_user' => ['type' => 'string']], 'required' => ['product']]],
            ['refKey' => 'skill_content_calendar', 'name' => '30-Day Content Calendar', 'framework' => 'bullseye', 'description' => 'Produce a 30-day content calendar with platform, date, copy, and CTA per entry.', 'input_schema' => ['type' => 'object', 'properties' => ['product' => ['type' => 'string'], 'platforms' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['product']]],
            ['refKey' => 'skill_kfactor_estimate', 'name' => 'K-Factor Viral Loop Estimate', 'framework' => 'k_factor', 'description' => 'Estimate viral coefficient and recommend loop improvements.', 'input_schema' => ['type' => 'object', 'properties' => ['product' => ['type' => 'string'], 'invites_per_user' => ['type' => 'number'], 'conversion_rate' => ['type' => 'number']], 'required' => ['product']]],

            // Tech (3)
            ['refKey' => 'skill_three_day_mvp', 'name' => '3-Day MVP Scope', 'framework' => 'three_day_mvp', 'description' => 'Scope an MVP that can ship in 72 hours with a boring, proven stack.', 'input_schema' => ['type' => 'object', 'properties' => ['idea' => ['type' => 'string'], 'team_size' => ['type' => 'integer']], 'required' => ['idea']]],
            ['refKey' => 'skill_shape_up_sprint', 'name' => 'Shape Up Sprint Plan', 'framework' => 'shape_up', 'description' => 'Shape a piece of work into a 2-6 week sprint with a clear appetite.', 'input_schema' => ['type' => 'object', 'properties' => ['feature' => ['type' => 'string'], 'appetite_weeks' => ['type' => 'integer']], 'required' => ['feature']]],
            ['refKey' => 'skill_owasp_review', 'name' => 'OWASP Top 10 Code Review', 'framework' => 'owasp', 'description' => 'Review code for the OWASP Top 10 vulnerability categories.', 'input_schema' => ['type' => 'object', 'properties' => ['code_snippet' => ['type' => 'string'], 'language' => ['type' => 'string']], 'required' => ['code_snippet']]],

            // Sales (3)
            ['refKey' => 'skill_bant_qualify', 'name' => 'BANT Lead Qualification', 'framework' => 'bant', 'description' => 'Qualify a lead by Budget, Authority, Need, Timeline. Recommend next action.', 'input_schema' => ['type' => 'object', 'properties' => ['lead_info' => ['type' => 'string']], 'required' => ['lead_info']]],
            ['refKey' => 'skill_spin_discovery', 'name' => 'SPIN Discovery Questions', 'framework' => 'spin', 'description' => 'Generate SPIN discovery questions (Situation, Problem, Implication, Need-payoff) for a prospect.', 'input_schema' => ['type' => 'object', 'properties' => ['prospect_industry' => ['type' => 'string'], 'product' => ['type' => 'string']], 'required' => ['product']]],
            ['refKey' => 'skill_meddic_qualify', 'name' => 'MEDDIC Deal Assessment', 'framework' => 'meddic', 'description' => 'Assess a complex B2B deal using the MEDDIC framework.', 'input_schema' => ['type' => 'object', 'properties' => ['deal_context' => ['type' => 'string']], 'required' => ['deal_context']]],

            // Operations (3)
            ['refKey' => 'skill_okrs_draft', 'name' => 'OKRs Draft', 'framework' => 'okrs', 'description' => 'Draft quarterly OKRs with 1 objective + 3-5 measurable key results.', 'input_schema' => ['type' => 'object', 'properties' => ['team' => ['type' => 'string'], 'quarter' => ['type' => 'string']], 'required' => ['team']]],
            ['refKey' => 'skill_raci_matrix', 'name' => 'RACI Matrix', 'framework' => 'raci', 'description' => 'Build a RACI matrix (Responsible, Accountable, Consulted, Informed) for a workflow.', 'input_schema' => ['type' => 'object', 'properties' => ['workflow' => ['type' => 'string'], 'roles' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['workflow']]],
            ['refKey' => 'skill_sop_draft', 'name' => 'SOP Document Draft', 'framework' => 'lean_ops', 'description' => 'Turn a recurring task into a Standard Operating Procedure with steps and owners.', 'input_schema' => ['type' => 'object', 'properties' => ['task' => ['type' => 'string'], 'owner' => ['type' => 'string']], 'required' => ['task']]],

            // Finance (4)
            ['refKey' => 'skill_unit_economics', 'name' => 'Unit Economics Model', 'framework' => 'unit_economics', 'description' => 'Compute CAC, LTV, payback period, contribution margin per unit.', 'input_schema' => ['type' => 'object', 'properties' => ['pricing' => ['type' => 'number'], 'cogs' => ['type' => 'number'], 'cac' => ['type' => 'number'], 'retention_months' => ['type' => 'number']], 'required' => ['pricing']]],
            ['refKey' => 'skill_cash_flow_model', 'name' => '12-Month Cash Flow Model', 'framework' => 'cash_flow', 'description' => 'Model 12 months of operating cash flow under conservative, base, and aggressive scenarios.', 'input_schema' => ['type' => 'object', 'properties' => ['starting_cash' => ['type' => 'number'], 'monthly_burn' => ['type' => 'number'], 'monthly_revenue' => ['type' => 'number']], 'required' => ['starting_cash']]],
            ['refKey' => 'skill_npv_irr', 'name' => 'NPV / IRR Analysis', 'framework' => 'npv_irr', 'description' => 'Compute NPV and IRR for a candidate investment or hire.', 'input_schema' => ['type' => 'object', 'properties' => ['cash_flows' => ['type' => 'array', 'items' => ['type' => 'number']], 'discount_rate' => ['type' => 'number']], 'required' => ['cash_flows']]],
            ['refKey' => 'skill_bessemer_metrics', 'name' => 'Bessemer SaaS Metrics Benchmark', 'framework' => 'bessemer_metrics', 'description' => 'Benchmark ARR, net retention, CAC ratio, and magic number against Bessemer SaaS standards.', 'input_schema' => ['type' => 'object', 'properties' => ['arr' => ['type' => 'number'], 'net_retention' => ['type' => 'number'], 'cac_ratio' => ['type' => 'number']], 'required' => ['arr']]],
        ];

        $items = [];
        foreach ($skills as $s) {
            $items[] = [
                'type' => 'skill',
                'ref_key' => $s['refKey'],
                'name' => $s['name'],
                'description' => $s['description'],
                'snapshot' => [
                    'type' => 'llm',
                    'framework' => $s['framework'],
                    'risk_level' => 'low',
                    'input_schema' => $s['input_schema'],
                    'output_schema' => ['type' => 'object', 'properties' => ['result' => ['type' => 'string']]],
                    'configuration' => [],
                    'system_prompt' => $this->skillSystemPrompt($s['name'], $s['description']),
                ],
            ];
        }

        return $items;
    }

    private function skillSystemPrompt(string $name, string $description): string
    {
        return "You are the {$name} skill. {$description}\n\nProduce a concise, actionable output. Cite the framework explicitly. If the input is insufficient, ask one specific clarifying question rather than making broad assumptions.";
    }

    /**
     * @return array<int, array{type: string, ref_key: string, name: string, description: string, snapshot: array<string, mixed>}>
     */
    private function workflowItems(): array
    {
        return [
            $this->workflow('validate_idea', 'Validate an Idea',
                'Take a raw idea through market sizing, RICE scoring, and competitor scanning in one pass.',
                [
                    ['id' => 'n1', 'type' => 'start', 'label' => 'Start', 'x' => 100, 'y' => 200],
                    ['id' => 'n2', 'type' => 'agent', 'label' => 'Market Sizing', 'x' => 260, 'y' => 200],
                    ['id' => 'n3', 'type' => 'agent', 'label' => 'RICE Scoring', 'x' => 440, 'y' => 200],
                    ['id' => 'n4', 'type' => 'agent', 'label' => 'Lean Validation Plan', 'x' => 620, 'y' => 200],
                    ['id' => 'n5', 'type' => 'end', 'label' => 'End', 'x' => 800, 'y' => 200],
                ],
                [['n1', 'n2'], ['n2', 'n3'], ['n3', 'n4'], ['n4', 'n5']],
            ),
            $this->workflow('build_mvp', 'Build an MVP',
                'Scope a 3-Day MVP, plan a Shape Up sprint, and draft the launch landing page.',
                [
                    ['id' => 'n1', 'type' => 'start', 'label' => 'Start', 'x' => 100, 'y' => 200],
                    ['id' => 'n2', 'type' => 'agent', 'label' => '3-Day MVP Scope', 'x' => 280, 'y' => 200],
                    ['id' => 'n3', 'type' => 'agent', 'label' => 'Shape Up Sprint', 'x' => 460, 'y' => 200],
                    ['id' => 'n4', 'type' => 'agent', 'label' => 'Landing Page Draft', 'x' => 640, 'y' => 200],
                    ['id' => 'n5', 'type' => 'end', 'label' => 'End', 'x' => 820, 'y' => 200],
                ],
                [['n1', 'n2'], ['n2', 'n3'], ['n3', 'n4'], ['n4', 'n5']],
            ),
            $this->workflow('get_first_customers', 'Get First Customers',
                'Pick channels, build a 30-day content calendar, and draft a 7-touch cold sequence.',
                [
                    ['id' => 'n1', 'type' => 'start', 'label' => 'Start', 'x' => 100, 'y' => 200],
                    ['id' => 'n2', 'type' => 'agent', 'label' => 'Bullseye Channels', 'x' => 280, 'y' => 200],
                    ['id' => 'n3', 'type' => 'agent', 'label' => 'Content Calendar', 'x' => 460, 'y' => 200],
                    ['id' => 'n4', 'type' => 'agent', 'label' => 'Cold Sequence', 'x' => 640, 'y' => 200],
                    ['id' => 'n5', 'type' => 'end', 'label' => 'End', 'x' => 820, 'y' => 200],
                ],
                [['n1', 'n2'], ['n2', 'n3'], ['n3', 'n4'], ['n4', 'n5']],
            ),
            $this->workflow('raise_funding', 'Raise Funding',
                'Produce unit economics, cash flow model, and pitch deck outline — ready for investor intros.',
                [
                    ['id' => 'n1', 'type' => 'start', 'label' => 'Start', 'x' => 100, 'y' => 200],
                    ['id' => 'n2', 'type' => 'agent', 'label' => 'Unit Economics', 'x' => 280, 'y' => 200],
                    ['id' => 'n3', 'type' => 'agent', 'label' => 'Cash Flow Model', 'x' => 460, 'y' => 200],
                    ['id' => 'n4', 'type' => 'agent', 'label' => 'Pitch Deck Outline', 'x' => 640, 'y' => 200],
                    ['id' => 'n5', 'type' => 'end', 'label' => 'End', 'x' => 820, 'y' => 200],
                ],
                [['n1', 'n2'], ['n2', 'n3'], ['n3', 'n4'], ['n4', 'n5']],
            ),
            $this->workflow('scale_operations', 'Scale Operations',
                'Draft OKRs, SOPs, and a RACI matrix for a scaling team.',
                [
                    ['id' => 'n1', 'type' => 'start', 'label' => 'Start', 'x' => 100, 'y' => 200],
                    ['id' => 'n2', 'type' => 'agent', 'label' => 'OKRs Draft', 'x' => 280, 'y' => 200],
                    ['id' => 'n3', 'type' => 'agent', 'label' => 'SOP Draft', 'x' => 460, 'y' => 200],
                    ['id' => 'n4', 'type' => 'agent', 'label' => 'RACI Matrix', 'x' => 640, 'y' => 200],
                    ['id' => 'n5', 'type' => 'end', 'label' => 'End', 'x' => 820, 'y' => 200],
                ],
                [['n1', 'n2'], ['n2', 'n3'], ['n3', 'n4'], ['n4', 'n5']],
            ),
        ];
    }

    /**
     * @param  array<int, array{id: string, type: string, label: string, x: int, y: int}>  $nodes
     * @param  array<int, array{0: string, 1: string}>  $edges
     * @return array{type: string, ref_key: string, name: string, description: string, snapshot: array<string, mixed>}
     */
    private function workflow(string $refKey, string $name, string $description, array $nodes, array $edges): array
    {
        $order = 0;
        $nodeSnapshots = array_map(function (array $n) use (&$order): array {
            return [
                'id' => $n['id'],
                'type' => $n['type'],
                'label' => $n['label'],
                'position_x' => $n['x'],
                'position_y' => $n['y'],
                'config' => [],
                'order' => $order++,
            ];
        }, $nodes);

        $sortOrder = 0;
        $edgeSnapshots = array_map(function (array $e) use (&$sortOrder): array {
            return [
                'source_node_id' => $e[0],
                'target_node_id' => $e[1],
                'condition' => null,
                'label' => null,
                'is_default' => true,
                'sort_order' => $sortOrder++,
            ];
        }, $edges);

        return [
            'type' => 'workflow',
            'ref_key' => 'workflow_'.$refKey,
            'name' => $name,
            'description' => $description,
            'snapshot' => [
                'description' => $description,
                'max_loop_iterations' => 5,
                'estimated_cost_credits' => 10,
                'settings' => [],
                'nodes' => $nodeSnapshots,
                'edges' => $edgeSnapshots,
            ],
        ];
    }

    /**
     * Maps workflow nodes to the persona agent that should drive them.
     * Also attaches every skill to the corresponding agent via agent_skill pivot.
     *
     * @return array<int, array<string, string>>
     */
    private function entityRefs(): array
    {
        $nodeToAgent = [
            // validate_idea
            ['Market Sizing', 'agent_product'],
            ['RICE Scoring', 'agent_product'],
            ['Lean Validation Plan', 'agent_product'],
            // build_mvp
            ['3-Day MVP Scope', 'agent_product'],
            ['Shape Up Sprint', 'agent_tech'],
            ['Landing Page Draft', 'agent_marketing'],
            // get_first_customers
            ['Bullseye Channels', 'agent_marketing'],
            ['Content Calendar', 'agent_marketing'],
            ['Cold Sequence', 'agent_sales'],
            // raise_funding
            ['Unit Economics', 'agent_finance'],
            ['Cash Flow Model', 'agent_finance'],
            ['Pitch Deck Outline', 'agent_finance'],
            // scale_operations
            ['OKRs Draft', 'agent_operations'],
            ['SOP Draft', 'agent_operations'],
            ['RACI Matrix', 'agent_operations'],
        ];

        $workflowByLabel = [
            'Market Sizing' => 'workflow_validate_idea',
            'RICE Scoring' => 'workflow_validate_idea',
            'Lean Validation Plan' => 'workflow_validate_idea',
            '3-Day MVP Scope' => 'workflow_build_mvp',
            'Shape Up Sprint' => 'workflow_build_mvp',
            'Landing Page Draft' => 'workflow_build_mvp',
            'Bullseye Channels' => 'workflow_get_first_customers',
            'Content Calendar' => 'workflow_get_first_customers',
            'Cold Sequence' => 'workflow_get_first_customers',
            'Unit Economics' => 'workflow_raise_funding',
            'Cash Flow Model' => 'workflow_raise_funding',
            'Pitch Deck Outline' => 'workflow_raise_funding',
            'OKRs Draft' => 'workflow_scale_operations',
            'SOP Draft' => 'workflow_scale_operations',
            'RACI Matrix' => 'workflow_scale_operations',
        ];

        $refs = [];

        foreach ($nodeToAgent as [$label, $agentRef]) {
            $refs[] = [
                'workflow_ref' => $workflowByLabel[$label],
                'node_label' => $label,
                'agent_ref' => $agentRef,
            ];
        }

        $agentToSkills = [
            'agent_product' => ['skill_rice_scoring', 'skill_market_sizing', 'skill_lean_validation', 'skill_kano_classify'],
            'agent_marketing' => ['skill_bullseye_channels', 'skill_content_calendar', 'skill_kfactor_estimate'],
            'agent_tech' => ['skill_three_day_mvp', 'skill_shape_up_sprint', 'skill_owasp_review'],
            'agent_sales' => ['skill_bant_qualify', 'skill_spin_discovery', 'skill_meddic_qualify'],
            'agent_operations' => ['skill_okrs_draft', 'skill_raci_matrix', 'skill_sop_draft'],
            'agent_finance' => ['skill_unit_economics', 'skill_cash_flow_model', 'skill_npv_irr', 'skill_bessemer_metrics'],
        ];

        foreach ($agentToSkills as $agentRef => $skillRefs) {
            foreach ($skillRefs as $skillRef) {
                $refs[] = [
                    'agent_ref' => $agentRef,
                    'skill_ref' => $skillRef,
                ];
            }
        }

        return $refs;
    }

    private function readme(): string
    {
        return <<<'MD'
# Founder Mode

Your six AI co-founders, wired to 20 proven frameworks, shipping 5 ready-to-run workflows.

## Who's in the pack

| Persona | Frameworks |
|---|---|
| **Product Co-Founder** | RICE, Kano, TAM/SAM/SOM, Lean Startup |
| **Marketing Co-Founder** | Bullseye, K-Factor, 30-Day Content Calendar |
| **Tech Co-Founder** | 3-Day MVP, Shape Up, OWASP |
| **Sales Co-Founder** | BANT, SPIN, MEDDIC |
| **Operations Co-Founder** | OKRs, RACI, Lean Ops |
| **Finance Co-Founder** | Unit Economics, Cash Flow, NPV/IRR, Bessemer Metrics |

## The 5 workflows

1. **Validate an Idea** — market sizing, RICE, lean validation plan.
2. **Build an MVP** — 3-Day scope, Shape Up sprint, landing page draft.
3. **Get First Customers** — Bullseye channels, content calendar, cold sequence.
4. **Raise Funding** — unit economics, cash flow model, pitch deck outline.
5. **Scale Operations** — OKRs, SOPs, RACI matrix.

## After install

1. Check your LLM provider in **Team Settings**.
2. Open **Workflows** and run one. Start with "Validate an Idea" if you're just beginning.
3. Tweak each agent's system prompt to match your voice — the defaults are a solid baseline, not the final word.

Each workflow produces real deliverables (financial models, pitch decks, content calendars, SOPs) that render as typed artifacts — not raw JSON.
MD;
    }
}
