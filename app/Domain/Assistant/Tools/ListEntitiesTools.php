<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Agent\Models\Agent;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Crew\Models\Crew;
use App\Domain\Email\Models\EmailTemplate;
use App\Domain\Email\Models\EmailTheme;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Project\Models\Project;
use App\Domain\Skill\Models\Skill;
use App\Domain\Website\Models\Website;
use App\Domain\Workflow\Models\Workflow;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;

class ListEntitiesTools
{
    /**
     * @return array<PrismToolObject>
     */
    public static function tools(): array
    {
        return [
            self::listExperiments(),
            self::listProjects(),
            self::listAgents(),
            self::listSkills(),
            self::listCrews(),
            self::listWorkflows(),
            self::listPendingApprovals(),
            self::listEmailTemplates(),
            self::listEmailThemes(),
            self::listWebsites(),
            self::listWebsitePages(),
        ];
    }

    private static function listExperiments(): PrismToolObject
    {
        return PrismTool::as('list_experiments')
            ->for('List experiments with optional status filter')
            ->withStringParameter('status', 'Filter by status (e.g. draft, running, completed, failed, paused, killed)')
            ->withNumberParameter('limit', 'Max results to return (default 10)')
            ->using(function (?string $status = null, ?int $limit = null) {
                $query = Experiment::query()->orderByDesc('created_at');

                if ($status) {
                    $query->where('status', $status);
                }

                $experiments = $query->limit($limit ?? 10)->get(['id', 'title', 'status', 'track', 'budget_spent_credits', 'budget_cap_credits', 'created_at']);

                return json_encode([
                    'count' => $experiments->count(),
                    'experiments' => $experiments->map(fn ($e) => [
                        'id' => $e->id,
                        'title' => $e->title,
                        'status' => $e->status->value,
                        'track' => $e->track->value,
                        'budget' => "{$e->budget_spent_credits}/{$e->budget_cap_credits}",
                        'created' => $e->created_at->diffForHumans(),
                    ])->toArray(),
                ]);
            });
    }

    private static function listProjects(): PrismToolObject
    {
        return PrismTool::as('list_projects')
            ->for('List projects with optional status filter')
            ->withStringParameter('status', 'Filter by status (e.g. draft, active, paused, completed, archived)')
            ->withNumberParameter('limit', 'Max results to return (default 10)')
            ->using(function (?string $status = null, ?int $limit = null) {
                $query = Project::query()->orderByDesc('created_at');

                if ($status) {
                    $query->where('status', $status);
                }

                $projects = $query->limit($limit ?? 10)->get(['id', 'title', 'type', 'status', 'created_at']);

                return json_encode([
                    'count' => $projects->count(),
                    'projects' => $projects->map(fn ($p) => [
                        'id' => $p->id,
                        'title' => $p->title,
                        'type' => $p->type->value,
                        'status' => $p->status->value,
                        'created' => $p->created_at->diffForHumans(),
                    ])->toArray(),
                ]);
            });
    }

    private static function listAgents(): PrismToolObject
    {
        return PrismTool::as('list_agents')
            ->for('List AI agents with optional status filter')
            ->withStringParameter('status', 'Filter by status (e.g. active, disabled)')
            ->withNumberParameter('limit', 'Max results to return (default 10)')
            ->using(function (?string $status = null, ?int $limit = null) {
                $query = Agent::query()->orderBy('name');

                if ($status) {
                    $query->where('status', $status);
                }

                $agents = $query->limit($limit ?? 10)->get(['id', 'name', 'role', 'provider', 'model', 'status']);

                return json_encode([
                    'count' => $agents->count(),
                    'agents' => $agents->map(fn ($a) => [
                        'id' => $a->id,
                        'name' => $a->name,
                        'role' => $a->role,
                        'provider' => $a->provider,
                        'model' => $a->model,
                        'status' => $a->status->value,
                    ])->toArray(),
                ]);
            });
    }

    private static function listSkills(): PrismToolObject
    {
        return PrismTool::as('list_skills')
            ->for('List available skills with optional type filter')
            ->withStringParameter('type', 'Filter by type (e.g. llm, connector, rule, hybrid)')
            ->withNumberParameter('limit', 'Max results to return (default 10)')
            ->using(function (?string $type = null, ?int $limit = null) {
                $query = Skill::query()->orderBy('name');

                if ($type) {
                    $query->where('type', $type);
                }

                $skills = $query->limit($limit ?? 10)->get(['id', 'name', 'type', 'status']);

                return json_encode([
                    'count' => $skills->count(),
                    'skills' => $skills->map(fn ($s) => [
                        'id' => $s->id,
                        'name' => $s->name,
                        'type' => $s->type->value,
                        'status' => $s->status->value,
                    ])->toArray(),
                ]);
            });
    }

    private static function listCrews(): PrismToolObject
    {
        return PrismTool::as('list_crews')
            ->for('List crews (multi-agent teams)')
            ->withStringParameter('status', 'Filter by status')
            ->withNumberParameter('limit', 'Max results to return (default 10)')
            ->using(function (?string $status = null, ?int $limit = null) {
                $query = Crew::query()->withCount('members')->orderBy('name');

                if ($status) {
                    $query->where('status', $status);
                }

                $crews = $query->limit($limit ?? 10)->get();

                return json_encode([
                    'count' => $crews->count(),
                    'crews' => $crews->map(fn ($c) => [
                        'id' => $c->id,
                        'name' => $c->name,
                        'status' => $c->status->value,
                        'members_count' => $c->members_count,
                    ])->toArray(),
                ]);
            });
    }

    private static function listWorkflows(): PrismToolObject
    {
        return PrismTool::as('list_workflows')
            ->for('List workflow templates')
            ->withStringParameter('status', 'Filter by status (e.g. draft, active, archived)')
            ->withNumberParameter('limit', 'Max results to return (default 10)')
            ->using(function (?string $status = null, ?int $limit = null) {
                $query = Workflow::query()->withCount('nodes')->orderBy('name');

                if ($status) {
                    $query->where('status', $status);
                }

                $workflows = $query->limit($limit ?? 10)->get();

                return json_encode([
                    'count' => $workflows->count(),
                    'workflows' => $workflows->map(fn ($w) => [
                        'id' => $w->id,
                        'name' => $w->name,
                        'status' => $w->status->value,
                        'nodes_count' => $w->nodes_count,
                    ])->toArray(),
                ]);
            });
    }

    private static function listPendingApprovals(): PrismToolObject
    {
        return PrismTool::as('list_pending_approvals')
            ->for('List pending approval requests that need review')
            ->withNumberParameter('limit', 'Max results to return (default 10)')
            ->using(function (?int $limit = null) {
                $approvals = ApprovalRequest::where('status', 'pending')
                    ->orderByDesc('created_at')
                    ->limit($limit ?? 10)
                    ->get(['id', 'type', 'payload', 'created_at']);

                return json_encode([
                    'count' => $approvals->count(),
                    'approvals' => $approvals->map(fn ($a) => [
                        'id' => $a->id,
                        'type' => $a->type,
                        'created' => $a->created_at->diffForHumans(),
                    ])->toArray(),
                ]);
            });
    }

    private static function listEmailTemplates(): PrismToolObject
    {
        return PrismTool::as('list_email_templates')
            ->for('List email templates with optional status or visibility filter')
            ->withStringParameter('status', 'Filter by status: draft, active, archived')
            ->withStringParameter('visibility', 'Filter by visibility: private, public')
            ->withNumberParameter('limit', 'Max results to return (default 10)')
            ->using(function (?string $status = null, ?string $visibility = null, ?int $limit = null) {
                $query = EmailTemplate::query()->orderByDesc('updated_at');

                if ($status) {
                    $query->where('status', $status);
                }

                if ($visibility) {
                    $query->where('visibility', $visibility);
                }

                $templates = $query->limit($limit ?? 10)->get();

                return json_encode([
                    'count' => $templates->count(),
                    'templates' => $templates->map(fn ($t) => [
                        'id' => $t->id,
                        'name' => $t->name,
                        'subject' => $t->subject,
                        'status' => $t->status->value,
                        'visibility' => $t->visibility->value,
                        'has_html_cache' => ! empty($t->html_cache),
                        'updated' => $t->updated_at->diffForHumans(),
                    ])->toArray(),
                ]);
            });
    }

    private static function listEmailThemes(): PrismToolObject
    {
        return PrismTool::as('list_email_themes')
            ->for('List email themes for the current team')
            ->withStringParameter('status', 'Filter by status: draft, active, archived')
            ->withNumberParameter('limit', 'Max results to return (default 10)')
            ->using(function (?string $status = null, ?int $limit = null) {
                $query = EmailTheme::query()->orderBy('name');

                if ($status) {
                    $query->where('status', $status);
                }

                $themes = $query->limit($limit ?? 10)->get();

                return json_encode([
                    'count' => $themes->count(),
                    'themes' => $themes->map(fn ($t) => [
                        'id' => $t->id,
                        'name' => $t->name,
                        'status' => $t->status->value,
                        'primary_color' => $t->primary_color,
                        'font_name' => $t->font_name,
                    ])->toArray(),
                ]);
            });
    }

    private static function listWebsites(): PrismToolObject
    {
        return PrismTool::as('list_websites')
            ->for('List websites for the current team with optional status filter')
            ->withStringParameter('status', 'Filter by status: draft, published')
            ->withNumberParameter('limit', 'Max results to return (default 10)')
            ->using(function (?string $status = null, ?int $limit = null) {
                $query = Website::query()->withCount('pages')->orderByDesc('updated_at');

                if ($status) {
                    $query->where('status', $status);
                }

                $websites = $query->limit($limit ?? 10)->get();

                return json_encode([
                    'count' => $websites->count(),
                    'websites' => $websites->map(fn ($w) => [
                        'id' => $w->id,
                        'name' => $w->name,
                        'slug' => $w->slug,
                        'status' => $w->status->value,
                        'pages_count' => $w->pages_count,
                        'custom_domain' => $w->custom_domain,
                        'updated' => $w->updated_at->diffForHumans(),
                    ])->toArray(),
                ]);
            });
    }

    private static function listWebsitePages(): PrismToolObject
    {
        return PrismTool::as('list_website_pages')
            ->for('List pages belonging to a specific website')
            ->withStringParameter('website_id', 'The parent website UUID', required: true)
            ->withStringParameter('status', 'Filter by status: draft, published')
            ->withStringParameter('page_type', 'Filter by type: page, post, product, landing')
            ->withNumberParameter('limit', 'Max results to return (default 20)')
            ->using(function (string $website_id, ?string $status = null, ?string $page_type = null, ?int $limit = null) {
                $website = Website::query()->find($website_id);
                if (! $website) {
                    return json_encode(['error' => 'Website not found']);
                }

                $query = $website->pages()->orderBy('sort_order');

                if ($status) {
                    $query->where('status', $status);
                }
                if ($page_type) {
                    $query->where('page_type', $page_type);
                }

                $pages = $query->limit($limit ?? 20)->get();

                return json_encode([
                    'website_id' => $website->id,
                    'count' => $pages->count(),
                    'pages' => $pages->map(fn ($p) => [
                        'id' => $p->id,
                        'title' => $p->title,
                        'slug' => $p->slug,
                        'page_type' => $p->page_type->value,
                        'status' => $p->status->value,
                        'has_exported_html' => ! empty($p->exported_html),
                        'published_at' => $p->published_at?->toIso8601String(),
                    ])->toArray(),
                ]);
            });
    }
}
