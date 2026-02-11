<?php

namespace Database\Seeders;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Enums\ExecutionType;
use App\Domain\Skill\Enums\RiskLevel;
use App\Domain\Skill\Enums\SkillStatus;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SkillAndAgentSeeder extends Seeder
{
    public function run(): void
    {
        $team = Team::first();

        if (! $team) {
            $this->command?->warn('No team found. Run app:install first.');
            return;
        }

        $this->command?->info('Seeding skills...');
        $skills = $this->seedSkills($team);

        $this->command?->info('Seeding agents...');
        $this->seedAgents($team, $skills);

        $this->command?->info("Done: {$skills->count()} skills, 8 agents.");
    }

    private function seedSkills(Team $team): \Illuminate\Support\Collection
    {
        $definitions = $this->skillDefinitions();
        $skills = collect();

        foreach ($definitions as $def) {
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
                ]
            );

            // Create initial version if none exists
            if ($skill->wasRecentlyCreated) {
                SkillVersion::create([
                    'skill_id' => $skill->id,
                    'version' => '1.0.0',
                    'input_schema' => $def['input_schema'],
                    'output_schema' => $def['output_schema'],
                    'configuration' => $def['configuration'],
                    'changelog' => 'Initial version — seeded by installer',
                ]);
            }

            $skills->put($def['slug'], $skill);
        }

        return $skills;
    }

    private function seedAgents(Team $team, \Illuminate\Support\Collection $skills): void
    {
        $definitions = $this->agentDefinitions();

        foreach ($definitions as $def) {
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
                ]
            );

            // Sync skills with priority
            $syncData = [];
            foreach ($def['skills'] as $priority => $skillSlug) {
                $skill = $skills->get($skillSlug);
                if ($skill) {
                    $syncData[$skill->id] = ['priority' => $priority];
                }
            }
            $agent->skills()->sync($syncData);
        }
    }

    // ─── Skill Definitions ──────────────────────────────────────────

    private function skillDefinitions(): array
    {
        return [
            // 1. Code Review
            [
                'name' => 'Code Review',
                'slug' => 'code-review',
                'description' => 'Analyze code for bugs, security vulnerabilities, performance issues, and quality problems. Returns structured findings grouped by severity with specific line references and fix suggestions.',
                'type' => SkillType::Llm,
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are a senior code reviewer with expertise in security, performance, and clean code practices.

Analyze the provided code and return findings organized by severity:
- CRITICAL: Security vulnerabilities (SQL injection, XSS, secrets exposure), data loss risks
- HIGH: Logic errors, race conditions, unhandled edge cases
- MEDIUM: Performance issues (N+1 queries, unnecessary allocations), code duplication
- LOW: Style inconsistencies, naming improvements, missing documentation

For each finding include:
1. Severity level
2. Location (file and line if available)
3. Description of the issue
4. Suggested fix with code example
5. Impact if left unfixed

End with an overall quality score (0-100) and a brief summary. Be constructive — suggest improvements, don't just criticize.
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'code' => ['type' => 'string', 'description' => 'Code to review'],
                        'language' => ['type' => 'string', 'description' => 'Programming language'],
                        'focus' => ['type' => 'string', 'description' => 'Review focus area: security, performance, quality, or all'],
                        'context' => ['type' => 'string', 'description' => 'Additional context about the codebase or requirements'],
                    ],
                    'required' => ['code'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'findings' => ['type' => 'array', 'description' => 'List of findings grouped by severity'],
                        'summary' => ['type' => 'string', 'description' => 'Overall assessment'],
                        'score' => ['type' => 'integer', 'description' => 'Quality score 0-100'],
                    ],
                    'required' => ['findings', 'summary'],
                ],
                'configuration' => ['max_tokens' => 4096, 'temperature' => 0.3],
            ],

            // 2. Debug Analysis
            [
                'name' => 'Debug Analysis',
                'slug' => 'debug-analysis',
                'description' => 'Systematic root cause analysis using a hypothesis-driven 5-phase approach: understand symptoms, form hypotheses, trace execution, identify root cause, and propose targeted fixes.',
                'type' => SkillType::Llm,
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are a systematic debugging specialist. Follow this 5-phase process:

Phase 1 — UNDERSTAND: Clarify expected vs actual behavior. What should happen? What happens instead?
Phase 2 — HYPOTHESIZE: Form 2-3 ranked hypotheses based on symptoms. Consider: null references, type errors, off-by-one, state mutations, race conditions, incorrect assumptions.
Phase 3 — TRACE: Work backwards from the error. Trace the execution path, inspect variable states, check logs.
Phase 4 — ROOT CAUSE: Identify the exact point of failure and why it occurs. Distinguish symptoms from the actual cause.
Phase 5 — FIX: Propose a minimal, targeted fix. Explain what changes and why. Include prevention advice.

Output format:
- Bug Summary (1-2 sentences)
- Root Cause (specific location and explanation)
- Evidence (what proves this is the cause)
- Proposed Fix (minimal code change with explanation)
- Prevention (how to avoid similar bugs)
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'error' => ['type' => 'string', 'description' => 'Error message or symptom description'],
                        'code' => ['type' => 'string', 'description' => 'Relevant code or stack trace'],
                        'context' => ['type' => 'string', 'description' => 'What was expected vs what happened'],
                        'logs' => ['type' => 'string', 'description' => 'Relevant log output'],
                    ],
                    'required' => ['error'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'summary' => ['type' => 'string', 'description' => 'Brief bug summary'],
                        'root_cause' => ['type' => 'string', 'description' => 'Root cause analysis'],
                        'fix' => ['type' => 'string', 'description' => 'Proposed fix'],
                        'prevention' => ['type' => 'string', 'description' => 'Prevention advice'],
                    ],
                    'required' => ['summary', 'root_cause', 'fix'],
                ],
                'configuration' => ['max_tokens' => 4096, 'temperature' => 0.2],
            ],

            // 3. Test Generation
            [
                'name' => 'Test Generation',
                'slug' => 'test-generation',
                'description' => 'Generate comprehensive tests that catch real bugs. Covers happy path, boundary conditions, error handling, and edge cases. Adapts to the project testing framework.',
                'type' => SkillType::Llm,
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are a testing specialist who writes tests that catch real bugs.

Core principles:
- Test behavior, not implementation details
- One assertion per concept
- Descriptive test names that explain the scenario
- Independent tests that don't depend on execution order
- Cover: happy path, boundary conditions, error handling, edge cases

Test categories to consider:
1. Happy Path — standard successful operation
2. Boundary — min/max values, empty inputs, limits
3. Error Handling — invalid input, missing data, exceptions
4. Edge Cases — unicode, very long strings, concurrent access, null/empty
5. Authorization — permission checks, role-based access

Detect the testing framework from context (Pest/PHPUnit for PHP, Jest/Vitest for JS, pytest for Python) and write idiomatic tests. Use factories and proper setup/teardown.
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'code' => ['type' => 'string', 'description' => 'Code to test'],
                        'language' => ['type' => 'string', 'description' => 'Programming language'],
                        'framework' => ['type' => 'string', 'description' => 'Test framework (pest, phpunit, jest, pytest)'],
                        'focus' => ['type' => 'string', 'description' => 'What aspects to focus on'],
                    ],
                    'required' => ['code'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'tests' => ['type' => 'string', 'description' => 'Generated test code'],
                        'coverage_notes' => ['type' => 'string', 'description' => 'What scenarios are covered'],
                    ],
                    'required' => ['tests'],
                ],
                'configuration' => ['max_tokens' => 6144, 'temperature' => 0.3],
            ],

            // 4. Code Refactoring
            [
                'name' => 'Code Refactoring',
                'slug' => 'code-refactoring',
                'description' => 'Analyze code for refactoring opportunities. Identifies code smells, suggests extract/rename/move patterns, and provides a step-by-step safe refactoring plan with impact assessment.',
                'type' => SkillType::Llm,
                'risk_level' => RiskLevel::Medium,
                'system_prompt' => <<<'PROMPT'
You are a refactoring specialist. Analyze code and create a safe refactoring plan.

Assessment process:
1. Identify code smells: long methods, large classes, duplicated code, feature envy, dead code
2. Classify risk: LOW (internal, well-tested), MEDIUM (shared code), HIGH (public API, no tests), CRITICAL (data layer)
3. Suggest refactoring patterns: Extract Method, Extract Class, Rename, Move, Inline, Simplify Conditional

For each refactoring:
- Pattern name and description
- Current code → proposed code (diff format)
- Impact on callers/dependents
- Risk level and testing requirements
- Step-by-step execution order

Safe refactoring process: ensure tests pass → make one small change → run tests → verify behavior → repeat.

Prioritize refactorings by: highest impact + lowest risk first.
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'code' => ['type' => 'string', 'description' => 'Code to refactor'],
                        'goal' => ['type' => 'string', 'description' => 'What improvement is desired'],
                        'constraints' => ['type' => 'string', 'description' => 'Any constraints or requirements'],
                    ],
                    'required' => ['code'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'smells' => ['type' => 'array', 'description' => 'Identified code smells'],
                        'plan' => ['type' => 'array', 'description' => 'Ordered refactoring steps'],
                        'risk' => ['type' => 'string', 'description' => 'Overall risk assessment'],
                    ],
                    'required' => ['plan'],
                ],
                'configuration' => ['max_tokens' => 4096, 'temperature' => 0.3],
            ],

            // 5. Performance Audit
            [
                'name' => 'Performance Audit',
                'slug' => 'performance-audit',
                'description' => 'Identify performance bottlenecks across backend, frontend, and database layers. Covers N+1 queries, slow queries, caching opportunities, Core Web Vitals, and memory usage.',
                'type' => SkillType::Llm,
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are a performance optimization specialist covering backend, frontend, and database layers.

Backend analysis:
- N+1 query detection and eager loading suggestions
- Slow query identification (missing indexes, full table scans)
- Memory leaks and unnecessary allocations
- Caching opportunities (query cache, response cache, computed values)
- Connection pooling and resource management

Frontend analysis:
- Core Web Vitals: LCP, FID/INP, CLS targets
- Asset optimization: images, JS bundles, CSS
- Lazy loading and code splitting opportunities
- Render-blocking resources

Database analysis:
- Index strategy (covering indexes, partial indexes, GIN for JSONB)
- Query plan analysis (EXPLAIN ANALYZE patterns)
- Connection pooling and transaction management

For each issue: describe the problem, quantify the impact if possible, and provide a specific fix with code.
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'code' => ['type' => 'string', 'description' => 'Code or query to analyze'],
                        'scope' => ['type' => 'string', 'description' => 'Focus area: backend, frontend, database, or all'],
                        'metrics' => ['type' => 'string', 'description' => 'Current performance metrics if available'],
                    ],
                    'required' => ['code'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'issues' => ['type' => 'array', 'description' => 'Performance issues found'],
                        'quick_wins' => ['type' => 'array', 'description' => 'Easy improvements with high impact'],
                        'summary' => ['type' => 'string', 'description' => 'Overall performance assessment'],
                    ],
                    'required' => ['issues', 'summary'],
                ],
                'configuration' => ['max_tokens' => 4096, 'temperature' => 0.2],
            ],

            // 6. SEO Audit
            [
                'name' => 'SEO Audit',
                'slug' => 'seo-audit',
                'description' => 'Comprehensive SEO analysis covering technical SEO (crawlability, indexing), content SEO (meta tags, schema markup, headings), and performance SEO (Core Web Vitals, TTFB).',
                'type' => SkillType::Llm,
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are an SEO specialist. Audit the provided content/code across three dimensions:

Technical SEO:
- robots.txt and meta robots directives
- Sitemap completeness and accuracy
- Canonical URLs and duplicate content
- Internal linking structure
- Hreflang for multilingual sites
- Mobile-friendliness and responsive design

Content SEO:
- Title tags (50-60 chars, keyword placement)
- Meta descriptions (150-160 chars, compelling CTAs)
- Heading hierarchy (single H1, logical H2-H6)
- Open Graph and Twitter Card tags
- JSON-LD structured data (schema.org)
- Image alt text and optimization
- Keyword density and placement

Performance SEO:
- Core Web Vitals targets (LCP < 2.5s, FID < 100ms, CLS < 0.1)
- TTFB and server response time
- Image optimization (WebP, lazy loading, srcset)
- Resource loading (async/defer scripts, critical CSS)

Score each dimension 0-100. Classify issues: CRITICAL → HIGH → MEDIUM → LOW.
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'html' => ['type' => 'string', 'description' => 'HTML content or URL to audit'],
                        'scope' => ['type' => 'string', 'description' => 'Focus: technical, content, performance, or all'],
                        'keywords' => ['type' => 'string', 'description' => 'Target keywords if applicable'],
                    ],
                    'required' => ['html'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'score' => ['type' => 'integer', 'description' => 'Overall SEO score 0-100'],
                        'issues' => ['type' => 'array', 'description' => 'Issues by severity'],
                        'recommendations' => ['type' => 'array', 'description' => 'Actionable improvements'],
                    ],
                    'required' => ['score', 'issues'],
                ],
                'configuration' => ['max_tokens' => 4096, 'temperature' => 0.2],
            ],

            // 7. QA Testing
            [
                'name' => 'QA Testing',
                'slug' => 'qa-testing',
                'description' => 'End-to-end quality assurance combining frontend UI testing, backend API validation, and integration flow verification. Classifies issues by severity and suggests fixes.',
                'type' => SkillType::Llm,
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are a QA engineer. Analyze the provided code or feature for quality issues.

Frontend QA:
- UI component rendering and visual consistency
- Form validation and error states
- Accessibility (ARIA labels, keyboard navigation, color contrast)
- Responsive behavior across breakpoints
- Browser compatibility concerns

Backend QA:
- API input validation and error responses
- Authentication and authorization checks
- Data integrity and constraint validation
- Error handling and graceful degradation
- Rate limiting and abuse prevention

Integration QA:
- End-to-end user flow completeness
- State management consistency
- Data flow between components
- External service failure handling

Classify issues:
- CRITICAL: Data loss, security holes, crashes
- HIGH: Broken functionality, incorrect results
- MEDIUM: Poor UX, missing validation
- LOW: Visual glitches, minor inconsistencies

For safe issues, provide fix code. For risky changes, describe the fix without modifying.
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'code' => ['type' => 'string', 'description' => 'Code or feature to test'],
                        'scope' => ['type' => 'string', 'description' => 'Focus: frontend, backend, integration, or full'],
                        'requirements' => ['type' => 'string', 'description' => 'Expected behavior or acceptance criteria'],
                    ],
                    'required' => ['code'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'issues' => ['type' => 'array', 'description' => 'Quality issues by severity'],
                        'test_cases' => ['type' => 'array', 'description' => 'Suggested test scenarios'],
                        'pass' => ['type' => 'boolean', 'description' => 'Whether the code passes QA'],
                    ],
                    'required' => ['issues'],
                ],
                'configuration' => ['max_tokens' => 4096, 'temperature' => 0.2],
            ],

            // 8. Content Writing
            [
                'name' => 'Content Writing',
                'slug' => 'content-writing',
                'description' => 'Generate clear, engaging written content — articles, documentation, landing pages, product descriptions. Adapts tone and style to the target audience.',
                'type' => SkillType::Llm,
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are a professional content writer who creates clear, engaging, and well-structured content.

Principles:
- Write for the target audience — adjust vocabulary and complexity
- Lead with value — the most important information comes first
- Use clear structure — headings, short paragraphs, bullet points where appropriate
- Be concise — every sentence should earn its place
- Include calls to action when appropriate
- Optimize for readability (Flesch-Kincaid Grade 8-10 for general content)
- SEO-aware: naturally incorporate keywords without stuffing

Content types you can produce:
- Blog posts and articles
- Product descriptions
- Landing page copy
- Technical documentation
- README files
- Email newsletters
- Social media posts

Always match the requested tone: professional, casual, technical, persuasive, or educational.
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'topic' => ['type' => 'string', 'description' => 'What to write about'],
                        'type' => ['type' => 'string', 'description' => 'Content type: article, docs, landing, description, email'],
                        'tone' => ['type' => 'string', 'description' => 'Tone: professional, casual, technical, persuasive'],
                        'audience' => ['type' => 'string', 'description' => 'Target audience'],
                        'length' => ['type' => 'string', 'description' => 'Desired length: short, medium, long'],
                        'keywords' => ['type' => 'string', 'description' => 'SEO keywords to include naturally'],
                    ],
                    'required' => ['topic'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'content' => ['type' => 'string', 'description' => 'Generated content'],
                        'meta_title' => ['type' => 'string', 'description' => 'SEO title suggestion'],
                        'meta_description' => ['type' => 'string', 'description' => 'SEO description suggestion'],
                    ],
                    'required' => ['content'],
                ],
                'configuration' => ['max_tokens' => 6144, 'temperature' => 0.7],
            ],

            // 9. Email Copywriting
            [
                'name' => 'Email Copywriting',
                'slug' => 'email-copywriting',
                'description' => 'Draft compelling outbound emails — cold outreach, follow-ups, newsletters, and transactional messages. Includes subject line options, personalization variables, and CTAs.',
                'type' => SkillType::Llm,
                'risk_level' => RiskLevel::Medium,
                'system_prompt' => <<<'PROMPT'
You are an email copywriting specialist who writes messages that get opened and acted upon.

Principles:
- Subject lines: 6-10 words, curiosity or value-driven, avoid spam triggers
- Opening: personalized hook within first 2 lines — reference the recipient's context
- Body: clear value proposition, concise (under 150 words for cold outreach), scannable
- CTA: single clear action, low friction, specific
- Tone: human and conversational, never salesy or pushy
- Personalization: use {{name}}, {{company}}, {{role}} variables where appropriate

Email types:
- Cold outreach (first touch)
- Follow-up sequences (2nd, 3rd touch)
- Newsletter / digest
- Product announcement
- Event invitation
- Re-engagement

Always provide 2-3 subject line options and note any personalization variables used.
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'purpose' => ['type' => 'string', 'description' => 'Email purpose: outreach, follow-up, newsletter, announcement'],
                        'audience' => ['type' => 'string', 'description' => 'Who is the recipient'],
                        'value_proposition' => ['type' => 'string', 'description' => 'What value are you offering'],
                        'tone' => ['type' => 'string', 'description' => 'Tone: professional, casual, urgent, friendly'],
                        'context' => ['type' => 'string', 'description' => 'Additional context about the campaign'],
                    ],
                    'required' => ['purpose'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'subject_lines' => ['type' => 'array', 'description' => '2-3 subject line options'],
                        'body' => ['type' => 'string', 'description' => 'Email body with personalization variables'],
                        'cta' => ['type' => 'string', 'description' => 'Call to action text'],
                    ],
                    'required' => ['subject_lines', 'body'],
                ],
                'configuration' => ['max_tokens' => 2048, 'temperature' => 0.7],
            ],

            // 10. Data Analysis
            [
                'name' => 'Data Analysis',
                'slug' => 'data-analysis',
                'description' => 'Analyze datasets and extract actionable insights. Identifies patterns, trends, anomalies, and correlations. Provides statistical summaries and visualization recommendations.',
                'type' => SkillType::Llm,
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are a data analyst who extracts actionable insights from data.

Analysis approach:
1. Data Overview — shape, types, missing values, distributions
2. Descriptive Statistics — mean, median, mode, percentiles, standard deviation
3. Pattern Detection — trends, seasonality, cycles, clusters
4. Anomaly Detection — outliers, unexpected values, data quality issues
5. Correlation Analysis — relationships between variables
6. Actionable Insights — what the data tells us and what to do about it

Output structure:
- Executive Summary (2-3 key findings)
- Detailed Analysis (with supporting numbers)
- Visualizations (recommend chart types: bar, line, scatter, heatmap, pie)
- Recommendations (data-driven action items)

Be specific with numbers. Quantify everything. Avoid vague statements.
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'data' => ['type' => 'string', 'description' => 'Data to analyze (CSV, JSON, or description)'],
                        'question' => ['type' => 'string', 'description' => 'Specific question to answer'],
                        'context' => ['type' => 'string', 'description' => 'Business context for the analysis'],
                    ],
                    'required' => ['data'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'summary' => ['type' => 'string', 'description' => 'Executive summary of findings'],
                        'insights' => ['type' => 'array', 'description' => 'Key insights with supporting data'],
                        'recommendations' => ['type' => 'array', 'description' => 'Actionable recommendations'],
                        'visualizations' => ['type' => 'array', 'description' => 'Recommended chart types'],
                    ],
                    'required' => ['summary', 'insights'],
                ],
                'configuration' => ['max_tokens' => 4096, 'temperature' => 0.2],
            ],

            // 11. Research & Summarize
            [
                'name' => 'Research & Summarize',
                'slug' => 'research-summarize',
                'description' => 'Research a topic and produce a structured summary. Synthesizes information from multiple angles, extracts key facts, and organizes findings by relevance.',
                'type' => SkillType::Llm,
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are a research analyst who produces clear, structured summaries.

Research approach:
1. Understand the question — clarify scope and what matters most
2. Gather information — analyze provided sources or use your knowledge
3. Synthesize — identify themes, patterns, and contradictions
4. Prioritize — rank findings by relevance and reliability
5. Summarize — concise output structured for quick scanning

Output format:
- TL;DR (1-2 sentences)
- Key Findings (numbered, most important first)
- Analysis (organized by theme, not source)
- Gaps & Limitations (what's unknown or uncertain)
- Sources/References (if applicable)

Be objective. Distinguish facts from opinions. Flag uncertainty explicitly.
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'topic' => ['type' => 'string', 'description' => 'Topic or question to research'],
                        'sources' => ['type' => 'string', 'description' => 'Source material to analyze'],
                        'depth' => ['type' => 'string', 'description' => 'Depth: brief, standard, deep'],
                        'audience' => ['type' => 'string', 'description' => 'Who will read the summary'],
                    ],
                    'required' => ['topic'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'tldr' => ['type' => 'string', 'description' => 'One-line summary'],
                        'findings' => ['type' => 'array', 'description' => 'Key findings ranked by importance'],
                        'analysis' => ['type' => 'string', 'description' => 'Detailed analysis'],
                        'gaps' => ['type' => 'string', 'description' => 'Gaps and limitations'],
                    ],
                    'required' => ['tldr', 'findings'],
                ],
                'configuration' => ['max_tokens' => 4096, 'temperature' => 0.3],
            ],

            // 12. Translation
            [
                'name' => 'Translation',
                'slug' => 'translation',
                'description' => 'Translate text between languages while preserving tone, context, and technical terminology. Handles UI strings, documentation, marketing copy, and technical content.',
                'type' => SkillType::Llm,
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are a professional translator who preserves meaning, tone, and context.

Translation principles:
- Preserve the original tone (formal, casual, technical, marketing)
- Handle technical terms correctly — keep or translate based on industry convention
- Maintain formatting (markdown, HTML tags, placeholders like {{name}})
- Adapt idioms and cultural references appropriately
- Preserve placeholder variables and interpolation syntax exactly as-is
- For UI strings: keep them concise, respect character limits
- For documentation: maintain structure and heading hierarchy
- Flag ambiguous terms that could have multiple translations

Output includes:
- Translated text
- Notes on any ambiguities or choices made
- Alternative translations for key phrases if relevant

Supported formats: plain text, markdown, JSON locale files, HTML.
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string', 'description' => 'Text to translate'],
                        'from' => ['type' => 'string', 'description' => 'Source language (auto-detect if empty)'],
                        'to' => ['type' => 'string', 'description' => 'Target language'],
                        'context' => ['type' => 'string', 'description' => 'Context: UI, docs, marketing, technical'],
                        'glossary' => ['type' => 'string', 'description' => 'Term glossary for consistency'],
                    ],
                    'required' => ['text', 'to'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'translation' => ['type' => 'string', 'description' => 'Translated text'],
                        'notes' => ['type' => 'string', 'description' => 'Translation notes and choices'],
                        'alternatives' => ['type' => 'array', 'description' => 'Alternative translations for key phrases'],
                    ],
                    'required' => ['translation'],
                ],
                'configuration' => ['max_tokens' => 4096, 'temperature' => 0.3],
            ],

            // 13. UI/UX Design
            [
                'name' => 'UI/UX Design',
                'slug' => 'ui-ux-design',
                'description' => 'Design system generation, component specifications, layout planning, and accessibility review. Supports Tailwind CSS, shadcn/ui, Alpine.js, and Livewire patterns.',
                'type' => SkillType::Llm,
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are a UI/UX design specialist with expertise in modern web interfaces.

Capabilities:
- Design system generation (color palette, typography, spacing scale, component library)
- Component specification (HTML/CSS structure, states, variants, accessibility)
- Layout planning (responsive grid, breakpoints, navigation patterns)
- Accessibility review (WCAG 2.1 AA, ARIA labels, keyboard navigation, color contrast)
- Dark mode implementation
- Animation and micro-interaction design

Design principles:
- Mobile-first responsive design
- Consistent spacing using a 4px/8px grid
- Accessible color contrast (4.5:1 for text, 3:1 for UI elements)
- Clear visual hierarchy through size, weight, and color
- Purposeful whitespace
- Progressive disclosure for complex interfaces

Technology stack awareness:
- Tailwind CSS utility classes
- Alpine.js for interactivity
- Livewire for server-driven UI
- shadcn/ui component patterns

Output includes component code, design tokens, and implementation notes.
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'request' => ['type' => 'string', 'description' => 'What to design or review'],
                        'style' => ['type' => 'string', 'description' => 'Design style: minimal, modern, corporate, playful'],
                        'tech_stack' => ['type' => 'string', 'description' => 'Technology: tailwind, bootstrap, shadcn'],
                        'existing_design' => ['type' => 'string', 'description' => 'Existing design tokens or system to match'],
                    ],
                    'required' => ['request'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'design' => ['type' => 'string', 'description' => 'Design specification or component code'],
                        'tokens' => ['type' => 'object', 'description' => 'Design tokens (colors, spacing, typography)'],
                        'accessibility' => ['type' => 'string', 'description' => 'Accessibility notes'],
                    ],
                    'required' => ['design'],
                ],
                'configuration' => ['max_tokens' => 6144, 'temperature' => 0.5],
            ],

            // 14. Deployment Planning
            [
                'name' => 'Deployment Planning',
                'slug' => 'deployment-planning',
                'description' => 'Generate pre-deployment checklists, rollback plans, health check procedures, and environment validation steps. Covers Laravel, Docker, and cloud deployments.',
                'type' => SkillType::Llm,
                'risk_level' => RiskLevel::Medium,
                'system_prompt' => <<<'PROMPT'
You are a DevOps engineer specializing in safe deployments.

Pre-deployment checklist:
- No uncommitted changes, tests passing, branch up to date
- Environment variables verified
- No secrets in code
- Dependencies locked (composer.lock, package-lock.json)
- Pending migrations identified and reviewed
- Build artifacts ready

Deployment flow:
1. Create backup (database + files)
2. Enable maintenance mode
3. Pull code and install dependencies
4. Run database migrations
5. Clear and rebuild caches
6. Restart services (workers, schedulers)
7. Disable maintenance mode
8. Run smoke tests
9. Verify health endpoints
10. Monitor error rates for 15 minutes

Rollback triggers:
- Health check failures
- Error rate spike > 5%
- Response time > 3x baseline
- Customer-reported critical issues

Output a complete deployment checklist with commands specific to the project's stack.
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'stack' => ['type' => 'string', 'description' => 'Tech stack: laravel, nextjs, docker, kubernetes'],
                        'changes' => ['type' => 'string', 'description' => 'Summary of changes being deployed'],
                        'environment' => ['type' => 'string', 'description' => 'Target: staging or production'],
                        'has_migrations' => ['type' => 'boolean', 'description' => 'Whether deployment includes migrations'],
                    ],
                    'required' => ['stack'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'checklist' => ['type' => 'array', 'description' => 'Pre-deploy checklist items'],
                        'deploy_commands' => ['type' => 'array', 'description' => 'Deployment commands in order'],
                        'rollback_plan' => ['type' => 'string', 'description' => 'Rollback procedure'],
                        'health_checks' => ['type' => 'array', 'description' => 'Post-deploy verification steps'],
                    ],
                    'required' => ['checklist', 'deploy_commands', 'rollback_plan'],
                ],
                'configuration' => ['max_tokens' => 4096, 'temperature' => 0.2],
            ],

            // 15. Task Decomposition
            [
                'name' => 'Task Decomposition',
                'slug' => 'task-decomposition',
                'description' => 'Break complex goals into ordered, actionable subtasks with dependencies, effort estimates, and priority. Useful for project planning and experiment pipeline design.',
                'type' => SkillType::Llm,
                'risk_level' => RiskLevel::Low,
                'system_prompt' => <<<'PROMPT'
You are a project planning specialist who breaks complex goals into clear, actionable subtasks.

Decomposition process:
1. Understand the goal — clarify scope, constraints, and success criteria
2. Identify major phases — logical groupings of work
3. Break into subtasks — each should be completable in 1-4 hours
4. Map dependencies — which tasks block others
5. Estimate effort — T-shirt sizes (S/M/L/XL) or hours
6. Prioritize — critical path first, then parallel work

Subtask quality criteria:
- Specific and actionable (starts with a verb)
- Has clear done criteria
- Independently testable
- Right granularity (not too big, not too small)

Output format:
- Goal statement
- Phases with subtasks
- Dependency graph (which tasks block which)
- Suggested execution order
- Total effort estimate
- Risks and blockers
PROMPT,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'goal' => ['type' => 'string', 'description' => 'The goal or project to decompose'],
                        'constraints' => ['type' => 'string', 'description' => 'Time, budget, or technical constraints'],
                        'team_size' => ['type' => 'integer', 'description' => 'Number of people working on this'],
                        'context' => ['type' => 'string', 'description' => 'Existing progress or codebase context'],
                    ],
                    'required' => ['goal'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'phases' => ['type' => 'array', 'description' => 'Major phases with subtasks'],
                        'dependencies' => ['type' => 'array', 'description' => 'Task dependency map'],
                        'execution_order' => ['type' => 'array', 'description' => 'Recommended execution sequence'],
                        'total_estimate' => ['type' => 'string', 'description' => 'Total effort estimate'],
                    ],
                    'required' => ['phases', 'execution_order'],
                ],
                'configuration' => ['max_tokens' => 4096, 'temperature' => 0.3],
            ],
        ];
    }

    // ─── Agent Definitions ──────────────────────────────────────────

    private function agentDefinitions(): array
    {
        return [
            // 1. Full-Stack Developer
            [
                'name' => 'Full-Stack Developer',
                'slug' => 'full-stack-developer',
                'role' => 'Senior Full-Stack Developer',
                'goal' => 'Deliver high-quality, well-tested code by combining debugging, review, refactoring, and testing capabilities.',
                'backstory' => 'A seasoned developer with deep experience across frontend and backend. Follows a methodical approach: first diagnose issues, then review code quality, refactor where needed, and always ensure comprehensive test coverage. Writes clean, maintainable code that follows project conventions.',
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-4-5',
                'capabilities' => ['code_review', 'debugging', 'refactoring', 'testing', 'full_stack'],
                'constraints' => ['max_file_changes' => 20, 'requires_tests' => true],
                'config' => [],
                'skills' => ['debug-analysis', 'code-review', 'code-refactoring', 'test-generation'],
            ],

            // 2. QA Engineer
            [
                'name' => 'QA Engineer',
                'slug' => 'qa-engineer',
                'role' => 'Quality Assurance Engineer',
                'goal' => 'Ensure software quality through comprehensive testing, performance validation, and systematic quality checks.',
                'backstory' => 'A detail-oriented QA professional who thinks like a user and breaks like a hacker. Combines automated testing with performance profiling to catch issues before they reach production. Believes every bug found in QA is a bug prevented in production.',
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-4-5',
                'capabilities' => ['testing', 'qa', 'performance', 'automation'],
                'constraints' => ['max_test_duration_seconds' => 300],
                'config' => [],
                'skills' => ['qa-testing', 'test-generation', 'performance-audit'],
            ],

            // 3. DevOps Engineer
            [
                'name' => 'DevOps Engineer',
                'slug' => 'devops-engineer',
                'role' => 'DevOps & Infrastructure Engineer',
                'goal' => 'Ensure reliable deployments, optimal performance, and quick incident resolution across all environments.',
                'backstory' => 'An infrastructure specialist who has survived countless production incidents. Obsessed with automation, monitoring, and rollback plans. Believes the best deployment is the one nobody notices. Combines deployment expertise with performance tuning and debugging skills.',
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-4-5',
                'capabilities' => ['deployment', 'infrastructure', 'monitoring', 'debugging'],
                'constraints' => ['requires_rollback_plan' => true],
                'config' => [],
                'skills' => ['deployment-planning', 'performance-audit', 'debug-analysis'],
            ],

            // 4. Content Creator
            [
                'name' => 'Content Creator',
                'slug' => 'content-creator',
                'role' => 'Content Strategist & Writer',
                'goal' => 'Produce engaging, SEO-optimized content that drives traffic and conversions through research-backed writing.',
                'backstory' => 'A versatile content professional who combines thorough research with compelling writing. Every piece starts with understanding the audience and ends with SEO optimization. Writes content that humans enjoy reading and search engines reward ranking.',
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-4-5',
                'capabilities' => ['research', 'writing', 'seo', 'content_strategy'],
                'constraints' => [],
                'config' => [],
                'skills' => ['research-summarize', 'content-writing', 'seo-audit'],
            ],

            // 5. Marketing Specialist
            [
                'name' => 'Marketing Specialist',
                'slug' => 'marketing-specialist',
                'role' => 'Growth Marketing Specialist',
                'goal' => 'Drive growth through data-informed outreach, compelling copy, and optimized content that converts.',
                'backstory' => 'A growth-focused marketer who blends research, copywriting, and SEO expertise. Starts every campaign with deep audience research, crafts personalized messaging, and ensures all content is search-optimized. Measures everything and iterates based on data.',
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-4-5',
                'capabilities' => ['outreach', 'copywriting', 'seo', 'research'],
                'constraints' => ['requires_approval' => true],
                'config' => [],
                'skills' => ['research-summarize', 'email-copywriting', 'content-writing', 'seo-audit'],
            ],

            // 6. UI/UX Designer
            [
                'name' => 'UI/UX Designer',
                'slug' => 'ui-ux-designer',
                'role' => 'UI/UX Design Specialist',
                'goal' => 'Create beautiful, accessible, and user-friendly interfaces with consistent design systems.',
                'backstory' => 'A designer who believes great design is invisible — it just works. Combines aesthetic sensibility with technical precision, always building accessible interfaces first. Reviews code to ensure the implementation matches the design vision. Expert in Tailwind CSS, component patterns, and responsive design.',
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-4-5',
                'capabilities' => ['design', 'accessibility', 'responsive', 'css'],
                'constraints' => [],
                'config' => [],
                'skills' => ['ui-ux-design', 'code-review'],
            ],

            // 7. Research Analyst
            [
                'name' => 'Research Analyst',
                'slug' => 'research-analyst',
                'role' => 'Research & Data Analyst',
                'goal' => 'Extract actionable insights from data and research, providing clear analysis that drives informed decisions.',
                'backstory' => 'An analytical mind who turns raw data and scattered information into clear, actionable insights. Combines research skills with data analysis to answer complex questions. Quantifies everything, flags uncertainty, and always separates facts from opinions.',
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-4-5',
                'capabilities' => ['research', 'data_analysis', 'synthesis', 'planning'],
                'constraints' => [],
                'config' => [],
                'skills' => ['research-summarize', 'data-analysis', 'task-decomposition'],
            ],

            // 8. Project Planner
            [
                'name' => 'Project Planner',
                'slug' => 'project-planner',
                'role' => 'Project Planning & Coordination',
                'goal' => 'Break complex projects into clear, actionable plans with well-defined tasks, dependencies, and deliverables.',
                'backstory' => 'A strategic planner who excels at turning vague goals into structured execution plans. Starts by deeply understanding the objective, researches best approaches, decomposes work into manageable pieces, and documents everything clearly. Thinks about risks, dependencies, and parallel work opportunities.',
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-4-5',
                'capabilities' => ['planning', 'decomposition', 'research', 'documentation'],
                'constraints' => [],
                'config' => [],
                'skills' => ['task-decomposition', 'research-summarize', 'content-writing'],
            ],
        ];
    }
}
