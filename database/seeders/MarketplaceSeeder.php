<?php

namespace Database\Seeders;

use App\Domain\Marketplace\Enums\ListingVisibility;
use App\Domain\Marketplace\Enums\MarketplaceStatus;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

class MarketplaceSeeder extends Seeder
{
    public function run(): void
    {
        $team = Team::first();
        $user = $team ? User::whereHas('teams', fn ($q) => $q->where('teams.id', $team->id))->first() : null;

        if (! $team || ! $user) {
            $this->command?->warn('No team/user found. Run app:install first.');

            return;
        }

        $created = 0;
        $skipped = 0;

        foreach ($this->listings() as $def) {
            $existing = MarketplaceListing::withoutGlobalScopes()
                ->where('slug', $def['slug'])
                ->first();

            if ($existing) {
                $existing->update([
                    'name' => $def['name'],
                    'description' => $def['description'],
                    'readme' => $def['readme'] ?? null,
                    'category' => $def['category'],
                    'tags' => $def['tags'],
                ]);
                $skipped++;

                continue;
            }

            MarketplaceListing::withoutGlobalScopes()->create([
                'team_id' => $team->id,
                'published_by' => $user->id,
                'type' => $def['type'],
                'listable_id' => null,
                'name' => $def['name'],
                'slug' => $def['slug'],
                'description' => $def['description'],
                'readme' => $def['readme'] ?? null,
                'category' => $def['category'],
                'tags' => $def['tags'],
                'status' => MarketplaceStatus::Published,
                'visibility' => ListingVisibility::Public,
                'version' => $def['version'] ?? '1.0.0',
                'configuration_snapshot' => $def['configuration_snapshot'] ?? [],
                'install_count' => 0,
                'avg_rating' => 0,
                'review_count' => 0,
                'is_official' => true,
                'monetization_enabled' => false,
                'price_per_run_credits' => 0,
                'execution_profile' => $def['execution_profile'] ?? [],
            ]);
            $created++;
        }

        $this->command?->info("Marketplace: {$created} created, {$skipped} updated.");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function listings(): array
    {
        return [
            // ─── Skills: Development & Engineering ─────────────────────────
            [
                'type' => 'skill',
                'name' => 'Code Review',
                'slug' => 'official-code-review',
                'description' => 'Automated code review with security, performance, and maintainability analysis. Supports PHP, Python, JavaScript, TypeScript, Go, Rust, and more.',
                'readme' => "## Code Review Skill\n\nAnalyzes pull requests and code changes for:\n- Security vulnerabilities (OWASP Top 10)\n- Performance bottlenecks\n- Code style and maintainability\n- Best practices compliance\n\n### Usage\nAttach to any agent and provide code or a diff as input.",
                'category' => 'development',
                'tags' => ['code-review', 'security', 'quality', 'engineering'],
                'configuration_snapshot' => ['type' => 'llm', 'risk_level' => 'safe'],
                'execution_profile' => ['avg_tokens' => 4000, 'avg_duration_ms' => 8000],
            ],
            [
                'type' => 'skill',
                'name' => 'Debug Analysis',
                'slug' => 'official-debug-analysis',
                'description' => 'Root cause analysis for bugs, errors, and stack traces. Explains the issue, identifies the source, and suggests fixes with code examples.',
                'category' => 'development',
                'tags' => ['debugging', 'errors', 'troubleshooting', 'engineering'],
                'configuration_snapshot' => ['type' => 'llm', 'risk_level' => 'safe'],
                'execution_profile' => ['avg_tokens' => 3500, 'avg_duration_ms' => 7000],
            ],
            [
                'type' => 'skill',
                'name' => 'Test Generation',
                'slug' => 'official-test-generation',
                'description' => 'Generate comprehensive test suites from code or specifications. PHPUnit, Jest, Pytest, and more. Covers happy paths, edge cases, and error scenarios.',
                'category' => 'development',
                'tags' => ['testing', 'tdd', 'automation', 'engineering'],
                'configuration_snapshot' => ['type' => 'llm', 'risk_level' => 'safe'],
                'execution_profile' => ['avg_tokens' => 5000, 'avg_duration_ms' => 10000],
            ],
            [
                'type' => 'skill',
                'name' => 'Code Refactoring',
                'slug' => 'official-code-refactoring',
                'description' => 'Intelligent code refactoring suggestions with before/after comparisons. Improves readability, reduces complexity, and applies design patterns.',
                'category' => 'development',
                'tags' => ['refactoring', 'clean-code', 'patterns', 'engineering'],
                'configuration_snapshot' => ['type' => 'llm', 'risk_level' => 'safe'],
            ],
            [
                'type' => 'skill',
                'name' => 'Performance Audit',
                'slug' => 'official-performance-audit',
                'description' => 'Analyze code and infrastructure for performance bottlenecks. Database query optimization, caching strategies, and load analysis.',
                'category' => 'development',
                'tags' => ['performance', 'optimization', 'database', 'engineering'],
                'configuration_snapshot' => ['type' => 'llm', 'risk_level' => 'safe'],
            ],
            [
                'type' => 'skill',
                'name' => 'Security Audit',
                'slug' => 'official-security-audit',
                'description' => 'Comprehensive security analysis: OWASP Top 10, dependency vulnerabilities, auth flows, input validation, and encryption practices.',
                'category' => 'development',
                'tags' => ['security', 'owasp', 'audit', 'compliance'],
                'configuration_snapshot' => ['type' => 'llm', 'risk_level' => 'safe'],
            ],
            [
                'type' => 'skill',
                'name' => 'API Design',
                'slug' => 'official-api-design',
                'description' => 'Design RESTful APIs with OpenAPI specs, endpoint planning, versioning strategy, auth patterns, and pagination design.',
                'category' => 'development',
                'tags' => ['api', 'rest', 'openapi', 'architecture'],
                'configuration_snapshot' => ['type' => 'llm', 'risk_level' => 'safe'],
            ],
            [
                'type' => 'skill',
                'name' => 'Database Design',
                'slug' => 'official-database-design',
                'description' => 'Schema design, normalization, indexing strategies, migration planning. Supports PostgreSQL, MySQL, SQLite, and MongoDB.',
                'category' => 'development',
                'tags' => ['database', 'schema', 'sql', 'architecture'],
                'configuration_snapshot' => ['type' => 'llm', 'risk_level' => 'safe'],
            ],
            [
                'type' => 'skill',
                'name' => 'SQL Optimization',
                'slug' => 'official-sql-optimization',
                'description' => 'Optimize slow SQL queries with EXPLAIN analysis, index suggestions, query rewriting, and partitioning strategies.',
                'category' => 'development',
                'tags' => ['sql', 'performance', 'database', 'optimization'],
                'configuration_snapshot' => ['type' => 'llm', 'risk_level' => 'safe'],
            ],
            [
                'type' => 'skill',
                'name' => 'Architecture Review',
                'slug' => 'official-architecture-review',
                'description' => 'Evaluate system architecture for scalability, reliability, and maintainability. Microservices, monoliths, event-driven, and CQRS patterns.',
                'category' => 'development',
                'tags' => ['architecture', 'scalability', 'patterns', 'engineering'],
                'configuration_snapshot' => ['type' => 'llm', 'risk_level' => 'safe'],
            ],

            // ─── Skills: Content & Marketing ──────────────────────────────
            [
                'type' => 'skill',
                'name' => 'Content Writing',
                'slug' => 'official-content-writing',
                'description' => 'Generate blog posts, articles, product descriptions, and technical documentation. SEO-optimized with customizable tone and style.',
                'category' => 'content',
                'tags' => ['writing', 'blog', 'seo', 'content'],
                'configuration_snapshot' => ['type' => 'llm', 'risk_level' => 'safe'],
            ],
            [
                'type' => 'skill',
                'name' => 'Email Copywriting',
                'slug' => 'official-email-copywriting',
                'description' => 'Craft high-converting email campaigns, drip sequences, and transactional emails. A/B test subject lines and optimize for deliverability.',
                'category' => 'content',
                'tags' => ['email', 'copywriting', 'marketing', 'conversion'],
                'configuration_snapshot' => ['type' => 'llm', 'risk_level' => 'safe'],
            ],
            [
                'type' => 'skill',
                'name' => 'Social Media Content',
                'slug' => 'official-social-media-content',
                'description' => 'Create engaging social media posts for Twitter/X, LinkedIn, Instagram, and Facebook. Platform-specific formatting and hashtag optimization.',
                'category' => 'content',
                'tags' => ['social-media', 'marketing', 'engagement', 'content'],
                'configuration_snapshot' => ['type' => 'llm', 'risk_level' => 'safe'],
            ],
            [
                'type' => 'skill',
                'name' => 'SEO Audit',
                'slug' => 'official-seo-audit',
                'description' => 'Comprehensive SEO analysis: meta tags, content structure, keyword density, internal linking, and technical SEO recommendations.',
                'category' => 'content',
                'tags' => ['seo', 'audit', 'marketing', 'optimization'],
                'configuration_snapshot' => ['type' => 'llm', 'risk_level' => 'safe'],
            ],

            // ─── Skills: Research & Analysis ──────────────────────────────
            [
                'type' => 'skill',
                'name' => 'Research & Summarize',
                'slug' => 'official-research-summarize',
                'description' => 'Deep research on any topic with structured summaries, source citations, and confidence levels. Multi-hop reasoning for complex questions.',
                'category' => 'research',
                'tags' => ['research', 'analysis', 'summarization', 'knowledge'],
                'configuration_snapshot' => ['type' => 'llm', 'risk_level' => 'safe'],
            ],
            [
                'type' => 'skill',
                'name' => 'Data Analysis',
                'slug' => 'official-data-analysis',
                'description' => 'Analyze datasets, generate insights, create visualizations, and build reports. CSV, JSON, and SQL data sources supported.',
                'category' => 'research',
                'tags' => ['data', 'analytics', 'visualization', 'reporting'],
                'configuration_snapshot' => ['type' => 'llm', 'risk_level' => 'safe'],
            ],
            [
                'type' => 'skill',
                'name' => 'Market Research',
                'slug' => 'official-market-research',
                'description' => 'Competitive analysis, market sizing, trend identification, and SWOT analysis. Structured reports with actionable recommendations.',
                'category' => 'research',
                'tags' => ['market', 'competitive', 'strategy', 'business'],
                'configuration_snapshot' => ['type' => 'llm', 'risk_level' => 'safe'],
            ],
            [
                'type' => 'skill',
                'name' => 'Translation',
                'slug' => 'official-translation',
                'description' => 'Translate text between 100+ languages with context awareness, tone preservation, and domain-specific terminology.',
                'category' => 'content',
                'tags' => ['translation', 'i18n', 'localization', 'multilingual'],
                'configuration_snapshot' => ['type' => 'llm', 'risk_level' => 'safe'],
            ],

            // ─── Skills: Operations ───────────────────────────────────────
            [
                'type' => 'skill',
                'name' => 'QA Testing',
                'slug' => 'official-qa-testing',
                'description' => 'Generate test plans, edge cases, and acceptance criteria from user stories or requirements. Manual and automated test strategies.',
                'category' => 'development',
                'tags' => ['qa', 'testing', 'quality', 'automation'],
                'configuration_snapshot' => ['type' => 'llm', 'risk_level' => 'safe'],
            ],
            [
                'type' => 'skill',
                'name' => 'Incident Response',
                'slug' => 'official-incident-response',
                'description' => 'Structured incident triage, root cause analysis, and post-mortem generation. Integrates with PagerDuty, Sentry, and Datadog alerts.',
                'category' => 'operations',
                'tags' => ['incident', 'sre', 'monitoring', 'devops'],
                'configuration_snapshot' => ['type' => 'llm', 'risk_level' => 'safe'],
            ],
            [
                'type' => 'skill',
                'name' => 'Deployment Planning',
                'slug' => 'official-deployment-planning',
                'description' => 'Create deployment checklists, rollback plans, and migration strategies. Blue-green, canary, and rolling deployment patterns.',
                'category' => 'operations',
                'tags' => ['deployment', 'ci-cd', 'devops', 'release'],
                'configuration_snapshot' => ['type' => 'llm', 'risk_level' => 'safe'],
            ],
            [
                'type' => 'skill',
                'name' => 'Task Decomposition',
                'slug' => 'official-task-decomposition',
                'description' => 'Break complex goals into actionable task lists with dependencies, estimates, and priority. Supports Agile, Kanban, and waterfall methodologies.',
                'category' => 'operations',
                'tags' => ['planning', 'project-management', 'agile', 'tasks'],
                'configuration_snapshot' => ['type' => 'llm', 'risk_level' => 'safe'],
            ],

            // ─── Agents ───────────────────────────────────────────────────
            [
                'type' => 'agent',
                'name' => 'Full-Stack Developer',
                'slug' => 'official-full-stack-developer',
                'description' => 'Senior full-stack developer agent. Code review, feature implementation, bug fixes, and architecture decisions. Equipped with code review, debug analysis, and test generation skills.',
                'category' => 'development',
                'tags' => ['developer', 'fullstack', 'engineering', 'coding'],
                'configuration_snapshot' => ['role' => 'Senior Full-Stack Developer', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'],
            ],
            [
                'type' => 'agent',
                'name' => 'QA Engineer',
                'slug' => 'official-qa-engineer',
                'description' => 'Dedicated QA agent for test planning, execution, and bug reporting. Generates test suites, runs regression checks, and tracks quality metrics.',
                'category' => 'development',
                'tags' => ['qa', 'testing', 'quality', 'automation'],
                'configuration_snapshot' => ['role' => 'QA Engineer', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'],
            ],
            [
                'type' => 'agent',
                'name' => 'DevOps Engineer',
                'slug' => 'official-devops-engineer',
                'description' => 'Infrastructure and deployment specialist. CI/CD pipelines, Docker, Kubernetes, monitoring, and incident response.',
                'category' => 'operations',
                'tags' => ['devops', 'infrastructure', 'ci-cd', 'deployment'],
                'configuration_snapshot' => ['role' => 'DevOps Engineer', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'],
            ],
            [
                'type' => 'agent',
                'name' => 'Content Creator',
                'slug' => 'official-content-creator',
                'description' => 'Versatile content agent for blogs, social media, emails, and documentation. SEO-aware with customizable brand voice.',
                'category' => 'content',
                'tags' => ['content', 'writing', 'marketing', 'social-media'],
                'configuration_snapshot' => ['role' => 'Content Creator', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'],
            ],
            [
                'type' => 'agent',
                'name' => 'Marketing Specialist',
                'slug' => 'official-marketing-specialist',
                'description' => 'Marketing strategy agent for campaign planning, audience segmentation, A/B testing ideas, and performance analysis.',
                'category' => 'content',
                'tags' => ['marketing', 'campaigns', 'analytics', 'growth'],
                'configuration_snapshot' => ['role' => 'Marketing Specialist', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'],
            ],
            [
                'type' => 'agent',
                'name' => 'Research Analyst',
                'slug' => 'official-research-analyst',
                'description' => 'Deep research agent with multi-hop reasoning. Market analysis, competitive intelligence, technology evaluation, and trend reports.',
                'category' => 'research',
                'tags' => ['research', 'analysis', 'intelligence', 'reports'],
                'configuration_snapshot' => ['role' => 'Research Analyst', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'],
            ],
            [
                'type' => 'agent',
                'name' => 'Sales Strategist',
                'slug' => 'official-sales-strategist',
                'description' => 'Sales enablement agent. Lead qualification frameworks, outreach sequences, objection handling, and deal analysis.',
                'category' => 'sales',
                'tags' => ['sales', 'leads', 'outreach', 'strategy'],
                'configuration_snapshot' => ['role' => 'Sales Strategist', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'],
            ],
            [
                'type' => 'agent',
                'name' => 'Security Engineer',
                'slug' => 'official-security-engineer',
                'description' => 'Security-focused agent for vulnerability assessment, penetration testing analysis, compliance checks, and security architecture review.',
                'category' => 'development',
                'tags' => ['security', 'pentest', 'compliance', 'engineering'],
                'configuration_snapshot' => ['role' => 'Security Engineer', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'],
            ],
            [
                'type' => 'agent',
                'name' => 'Solutions Architect',
                'slug' => 'official-solutions-architect',
                'description' => 'System design and architecture agent. Evaluates trade-offs, designs scalable systems, and creates technical decision documents.',
                'category' => 'development',
                'tags' => ['architecture', 'design', 'scalability', 'systems'],
                'configuration_snapshot' => ['role' => 'Solutions Architect', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'],
            ],
            [
                'type' => 'agent',
                'name' => 'Project Planner',
                'slug' => 'official-project-planner',
                'description' => 'Project management agent for task breakdown, milestone planning, resource allocation, and progress tracking.',
                'category' => 'operations',
                'tags' => ['project-management', 'planning', 'agile', 'tasks'],
                'configuration_snapshot' => ['role' => 'Project Planner', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'],
            ],
            [
                'type' => 'agent',
                'name' => 'UI/UX Designer',
                'slug' => 'official-ui-ux-designer',
                'description' => 'Design-focused agent for wireframes, user flows, accessibility audits, and design system recommendations.',
                'category' => 'design',
                'tags' => ['design', 'ui', 'ux', 'accessibility'],
                'configuration_snapshot' => ['role' => 'UI/UX Designer', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'],
            ],
            [
                'type' => 'agent',
                'name' => 'Database Architect',
                'slug' => 'official-database-architect',
                'description' => 'Database specialist agent. Schema design, query optimization, migration strategies, and performance tuning for PostgreSQL, MySQL, and MongoDB.',
                'category' => 'development',
                'tags' => ['database', 'sql', 'architecture', 'optimization'],
                'configuration_snapshot' => ['role' => 'Database Architect', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'],
            ],
        ];
    }
}
