<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Agent\Actions\CreateAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Approval\Actions\ApproveAction;
use App\Domain\Approval\Actions\RejectAction;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Crew\Actions\CreateCrewAction;
use App\Domain\Crew\Actions\ExecuteCrewAction;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Models\Crew;
use App\Domain\Email\Actions\CreateEmailTemplateAction;
use App\Domain\Email\Actions\DeleteEmailTemplateAction;
use App\Domain\Email\Actions\UpdateEmailTemplateAction;
use App\Domain\Email\Models\EmailTemplate;
use App\Domain\Email\Services\MjmlRenderer;
use App\Domain\Evolution\Enums\EvolutionProposalStatus;
use App\Domain\Evolution\Models\EvolutionProposal;
use App\Domain\Experiment\Actions\CreateExperimentAction;
use App\Domain\Experiment\Actions\KillExperimentAction;
use App\Domain\Experiment\Actions\PauseExperimentAction;
use App\Domain\Experiment\Actions\ResumeExperimentAction;
use App\Domain\Experiment\Actions\RetryExperimentAction;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Memory\Models\Memory;
use App\Domain\Project\Actions\ArchiveProjectAction;
use App\Domain\Project\Actions\CreateProjectAction;
use App\Domain\Project\Actions\PauseProjectAction;
use App\Domain\Project\Actions\ResumeProjectAction;
use App\Domain\Project\Actions\TriggerProjectRunAction;
use App\Domain\Project\Actions\UpdateProjectAction;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Enums\ProjectType;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Signal\Models\ConnectorBinding;
use App\Domain\Skill\Actions\CreateSkillAction;
use App\Domain\Skill\Actions\UpdateSkillAction;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Domain\Workflow\Actions\CreateWorkflowAction;
use App\Domain\Workflow\Actions\GenerateWorkflowFromPromptAction;
use App\Domain\Workflow\Actions\UpdateWorkflowAction;
use App\Domain\Workflow\Actions\ValidateWorkflowGraphAction;
use App\Domain\Workflow\Models\Workflow;
use App\Infrastructure\Auth\SanctumTokenIssuer;
use App\Models\GlobalSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;

class MutationTools
{
    /**
     * @return array<PrismToolObject>
     */
    public static function writeTools(): array
    {
        return [
            self::createProject(),
            self::createAgent(),
            self::createCrew(),
            self::executeCrew(),
            self::createSkill(),
            self::updateSkill(),
            self::createWorkflow(),
            self::saveWorkflowGraph(),
            self::generateWorkflow(),
            self::activateWorkflow(),
            self::createExperiment(),
            self::updateProject(),
            self::activateProject(),
            self::pauseProject(),
            self::resumeProject(),
            self::startExperiment(),
            self::pauseExperiment(),
            self::resumeExperiment(),
            self::retryExperiment(),
            self::triggerProjectRun(),
            self::approveRequest(),
            self::rejectRequest(),
            self::syncAgentSkills(),
            self::syncAgentTools(),
            self::updateGlobalSettings(),
            self::rejectEvolutionProposal(),
            self::uploadMemoryKnowledge(),
            self::createEmailTemplate(),
            self::updateEmailTemplate(),
        ];
    }

    /**
     * @return array<PrismToolObject>
     */
    public static function destructiveTools(): array
    {
        return [
            self::killExperiment(),
            self::archiveProject(),
            self::deleteAgent(),
            self::deleteMemory(),
            self::deleteConnectorBinding(),
            self::manageByokCredential(),
            self::manageApiToken(),
            self::deleteEmailTemplate(),
        ];
    }

    private static function createProject(): PrismToolObject
    {
        return PrismTool::as('create_project')
            ->for('Create a new project in FleetQ')
            ->withStringParameter('title', 'Project title', required: true)
            ->withStringParameter('description', 'Project description')
            ->withStringParameter('type', 'Project type: one_shot or continuous (default: one_shot)')
            ->using(function (string $title, ?string $description = null, ?string $type = null) {
                try {
                    $project = app(CreateProjectAction::class)->execute(
                        userId: auth()->id(),
                        title: $title,
                        type: $type && ProjectType::tryFrom($type) ? $type : ProjectType::OneShot->value,
                        description: $description,
                        teamId: auth()->user()->current_team_id,
                    );

                    return json_encode([
                        'success' => true,
                        'project_id' => $project->id,
                        'title' => $project->title,
                        'status' => $project->status->value,
                        'url' => route('projects.show', $project),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function createAgent(): PrismToolObject
    {
        return PrismTool::as('create_agent')
            ->for('Create a new AI agent')
            ->withStringParameter('name', 'Agent name', required: true)
            ->withStringParameter('role', 'Agent role description')
            ->withStringParameter('goal', 'Agent goal')
            ->withStringParameter('backstory', 'Agent backstory')
            ->withStringParameter('provider', 'LLM provider (anthropic, openai, google). Default: anthropic')
            ->withStringParameter('model', 'LLM model name. Default: claude-sonnet-4-5')
            ->using(function (string $name, ?string $role = null, ?string $goal = null, ?string $backstory = null, ?string $provider = null, ?string $model = null) {
                try {
                    $agent = app(CreateAgentAction::class)->execute(
                        name: $name,
                        provider: $provider ?? 'anthropic',
                        model: $model ?? 'claude-sonnet-4-5',
                        role: $role,
                        goal: $goal,
                        backstory: $backstory,
                        teamId: auth()->user()->current_team_id,
                    );

                    return json_encode([
                        'success' => true,
                        'agent_id' => $agent->id,
                        'name' => $agent->name,
                        'status' => $agent->status->value,
                        'url' => route('agents.show', $agent),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function createCrew(): PrismToolObject
    {
        return PrismTool::as('create_crew')
            ->for('Create a new crew (multi-agent team). Requires a coordinator agent and a QA agent.')
            ->withStringParameter('name', 'Crew name', required: true)
            ->withStringParameter('coordinator_agent_id', 'UUID of the coordinator agent', required: true)
            ->withStringParameter('qa_agent_id', 'UUID of the QA agent (must be different from coordinator)', required: true)
            ->withStringParameter('description', 'Crew description')
            ->withStringParameter('process_type', 'Process type: sequential, parallel, hierarchical (default: hierarchical)')
            ->using(function (string $name, string $coordinator_agent_id, string $qa_agent_id, ?string $description = null, ?string $process_type = null) {
                try {
                    $processType = CrewProcessType::tryFrom($process_type ?? '') ?? CrewProcessType::Hierarchical;

                    $crew = app(CreateCrewAction::class)->execute(
                        userId: auth()->id(),
                        name: $name,
                        coordinatorAgentId: $coordinator_agent_id,
                        qaAgentId: $qa_agent_id,
                        description: $description,
                        processType: $processType,
                        teamId: auth()->user()->current_team_id,
                    );

                    return json_encode([
                        'success' => true,
                        'crew_id' => $crew->id,
                        'name' => $crew->name,
                        'status' => $crew->status->value,
                        'url' => route('crews.show', $crew),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function executeCrew(): PrismToolObject
    {
        return PrismTool::as('execute_crew')
            ->for('Start a crew execution with a goal. The crew must be active.')
            ->withStringParameter('crew_id', 'The crew UUID', required: true)
            ->withStringParameter('goal', 'The goal or task for the crew to accomplish', required: true)
            ->using(function (string $crew_id, string $goal) {
                $crew = Crew::find($crew_id);
                if (! $crew) {
                    return json_encode(['error' => 'Crew not found']);
                }

                try {
                    $execution = app(ExecuteCrewAction::class)->execute(
                        crew: $crew,
                        goal: $goal,
                        teamId: auth()->user()->current_team_id,
                    );

                    return json_encode([
                        'success' => true,
                        'execution_id' => $execution->id,
                        'crew_name' => $crew->name,
                        'status' => $execution->status->value,
                        'url' => route('crews.show', $crew),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function createSkill(): PrismToolObject
    {
        return PrismTool::as('create_skill')
            ->for('Create a new reusable skill. Type must be one of: llm, connector, rule, hybrid.')
            ->withStringParameter('name', 'Skill name', required: true)
            ->withStringParameter('type', 'Skill type: llm, connector, rule, hybrid', required: true)
            ->withStringParameter('description', 'Skill description')
            ->withStringParameter('prompt_template', 'System prompt template for LLM-backed skills')
            ->using(function (string $name, string $type, ?string $description = null, ?string $prompt_template = null) {
                try {
                    $skillType = SkillType::tryFrom($type);
                    if (! $skillType) {
                        return json_encode(['error' => "Invalid skill type '{$type}'. Must be one of: llm, connector, rule, hybrid"]);
                    }

                    $skill = app(CreateSkillAction::class)->execute(
                        teamId: auth()->user()->current_team_id,
                        name: $name,
                        type: $skillType,
                        description: $description ?? '',
                        systemPrompt: $prompt_template,
                        createdBy: auth()->id(),
                    );

                    return json_encode([
                        'success' => true,
                        'skill_id' => $skill->id,
                        'name' => $skill->name,
                        'status' => $skill->status->value,
                        'url' => route('skills.show', $skill),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function updateSkill(): PrismToolObject
    {
        return PrismTool::as('update_skill')
            ->for('Update an existing skill (name, description, or prompt template)')
            ->withStringParameter('skill_id', 'The skill UUID', required: true)
            ->withStringParameter('name', 'New skill name')
            ->withStringParameter('description', 'New skill description')
            ->withStringParameter('prompt_template', 'New system prompt template')
            ->using(function (string $skill_id, ?string $name = null, ?string $description = null, ?string $prompt_template = null) {
                $skill = Skill::find($skill_id);
                if (! $skill) {
                    return json_encode(['error' => 'Skill not found']);
                }

                try {
                    $attributes = array_filter([
                        'name' => $name,
                        'description' => $description,
                        'system_prompt' => $prompt_template,
                    ], fn ($v) => $v !== null);

                    if (empty($attributes)) {
                        return json_encode(['error' => 'No attributes provided to update']);
                    }

                    $skill = app(UpdateSkillAction::class)->execute(
                        skill: $skill,
                        attributes: $attributes,
                        updatedBy: auth()->id(),
                    );

                    return json_encode([
                        'success' => true,
                        'skill_id' => $skill->id,
                        'name' => $skill->name,
                        'version' => $skill->current_version,
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function createWorkflow(): PrismToolObject
    {
        return PrismTool::as('create_workflow')
            ->for('Create a blank workflow template with default start and end nodes')
            ->withStringParameter('name', 'Workflow name', required: true)
            ->withStringParameter('description', 'Workflow description')
            ->using(function (string $name, ?string $description = null) {
                try {
                    $workflow = app(CreateWorkflowAction::class)->execute(
                        userId: auth()->id(),
                        name: $name,
                        description: $description,
                        teamId: auth()->user()->current_team_id,
                    );

                    return json_encode([
                        'success' => true,
                        'workflow_id' => $workflow->id,
                        'name' => $workflow->name,
                        'status' => $workflow->status->value,
                        'url' => route('workflows.show', $workflow),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function generateWorkflow(): PrismToolObject
    {
        return PrismTool::as('generate_workflow')
            ->for('Generate a full workflow DAG from a natural language description. Uses AI to decompose the prompt into nodes and edges. Note: this calls an LLM internally and incurs additional cost.')
            ->withStringParameter('prompt', 'Natural language description of the workflow to generate (min 10 characters)', required: true)
            ->using(function (string $prompt) {
                try {
                    $result = app(GenerateWorkflowFromPromptAction::class)->execute(
                        prompt: $prompt,
                        userId: auth()->id(),
                        teamId: auth()->user()->current_team_id,
                    );

                    $workflow = $result['workflow'];

                    if (! $workflow) {
                        return json_encode(['error' => 'Failed to generate workflow: '.implode(', ', $result['errors'])]);
                    }

                    $workflow->load(['nodes', 'edges']);

                    return json_encode([
                        'success' => true,
                        'workflow_id' => $workflow->id,
                        'name' => $workflow->name,
                        'description' => $workflow->description,
                        'node_count' => $workflow->nodes->count(),
                        'edge_count' => $workflow->edges->count(),
                        'status' => $workflow->status->value,
                        'validation_warnings' => $result['errors'],
                        'url' => route('workflows.show', $workflow),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function saveWorkflowGraph(): PrismToolObject
    {
        return PrismTool::as('save_workflow_graph')
            ->for('Save or replace the node/edge graph for an existing workflow. Use after create_workflow to add nodes and connections, or to fix a generated workflow. Nodes JSON: [{type,label,agent_id?,position_x?,position_y?,config?}]. Edges JSON: [{source_node_index,target_node_index,condition?,is_default?}]. Node types: start, end, agent, conditional, human_task, switch.')
            ->withStringParameter('workflow_id', 'UUID of the workflow to update', required: true)
            ->withStringParameter('nodes', 'JSON array of node objects. Example: [{"type":"start","label":"Start"},{"type":"agent","label":"Researcher","agent_id":"<uuid>"},{"type":"end","label":"End"}]', required: true)
            ->withStringParameter('edges', 'JSON array of edge objects using 0-based node indices. Example: [{"source_node_index":0,"target_node_index":1},{"source_node_index":1,"target_node_index":2}]', required: true)
            ->using(function (string $workflow_id, string $nodes, string $edges) {
                $workflow = Workflow::find($workflow_id);
                if (! $workflow) {
                    return json_encode(['error' => "Workflow not found: {$workflow_id}"]);
                }

                $nodesArray = json_decode($nodes, true);
                $edgesArray = json_decode($edges, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    return json_encode(['error' => 'Invalid JSON: '.json_last_error_msg()]);
                }

                // Remap source_node_index/target_node_index → source_node_id/target_node_id
                // UpdateWorkflowAction keys nodes by array index when no id field is present
                $remappedEdges = array_map(fn ($e) => array_merge($e, [
                    'source_node_id' => $e['source_node_index'] ?? $e['source_node_id'] ?? null,
                    'target_node_id' => $e['target_node_index'] ?? $e['target_node_id'] ?? null,
                ]), $edgesArray ?? []);

                try {
                    $updated = app(UpdateWorkflowAction::class)->execute(
                        workflow: $workflow,
                        nodes: $nodesArray ?? [],
                        edges: $remappedEdges,
                    );

                    $updated->load(['nodes', 'edges']);

                    return json_encode([
                        'success' => true,
                        'workflow_id' => $updated->id,
                        'name' => $updated->name,
                        'node_count' => $updated->nodes->count(),
                        'edge_count' => $updated->edges->count(),
                        'status' => $updated->status->value,
                        'url' => route('workflows.show', $updated),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function activateWorkflow(): PrismToolObject
    {
        return PrismTool::as('activate_workflow')
            ->for('Validate and activate a workflow so it can be used in experiments and projects. The graph must have valid start/end nodes.')
            ->withStringParameter('workflow_id', 'The workflow UUID', required: true)
            ->using(function (string $workflow_id) {
                $workflow = Workflow::find($workflow_id);
                if (! $workflow) {
                    return json_encode(['error' => 'Workflow not found']);
                }

                try {
                    $result = app(ValidateWorkflowGraphAction::class)->execute($workflow, activateIfValid: true);

                    if (! $result['valid']) {
                        return json_encode(['error' => 'Workflow graph is invalid: '.implode(', ', $result['errors'])]);
                    }

                    $workflow->refresh();

                    return json_encode([
                        'success' => true,
                        'workflow_id' => $workflow->id,
                        'name' => $workflow->name,
                        'status' => $workflow->status->value,
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function createExperiment(): PrismToolObject
    {
        return PrismTool::as('create_experiment')
            ->for('Create a new experiment. Track must be one of: growth, retention, revenue, engagement, debug.')
            ->withStringParameter('title', 'Experiment title', required: true)
            ->withStringParameter('thesis', 'Experiment hypothesis or objective (default: "To be defined")')
            ->withStringParameter('track', 'Experiment track: growth, retention, revenue, engagement, debug (default: growth)')
            ->withStringParameter('budget_cap_credits', 'Budget cap in credits (default: 10000)')
            ->withStringParameter('workflow_id', 'Optional workflow UUID to materialize into the experiment')
            ->using(function (string $title, ?string $thesis = null, ?string $track = null, ?string $budget_cap_credits = null, ?string $workflow_id = null) {
                try {
                    $experiment = app(CreateExperimentAction::class)->execute(
                        userId: auth()->id(),
                        title: $title,
                        thesis: $thesis ?? 'To be defined',
                        track: $track ?? 'growth',
                        budgetCapCredits: $budget_cap_credits ? (int) $budget_cap_credits : 10000,
                        teamId: auth()->user()->current_team_id,
                        workflowId: $workflow_id,
                    );

                    return json_encode([
                        'success' => true,
                        'experiment_id' => $experiment->id,
                        'title' => $experiment->title,
                        'status' => $experiment->status->value,
                        'url' => route('experiments.show', $experiment),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function updateProject(): PrismToolObject
    {
        return PrismTool::as('update_project')
            ->for('Update an existing project title or description')
            ->withStringParameter('project_id', 'The project UUID', required: true)
            ->withStringParameter('title', 'New project title')
            ->withStringParameter('description', 'New project description')
            ->using(function (string $project_id, ?string $title = null, ?string $description = null) {
                $project = Project::find($project_id);
                if (! $project) {
                    return json_encode(['error' => 'Project not found']);
                }

                try {
                    $data = array_filter(['title' => $title, 'description' => $description], fn ($v) => $v !== null);

                    if (empty($data)) {
                        return json_encode(['error' => 'No attributes provided to update']);
                    }

                    $project = app(UpdateProjectAction::class)->execute($project, $data);

                    return json_encode([
                        'success' => true,
                        'project_id' => $project->id,
                        'title' => $project->title,
                        'status' => $project->status->value,
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function pauseProject(): PrismToolObject
    {
        return PrismTool::as('pause_project')
            ->for('Pause an active project and its schedule')
            ->withStringParameter('project_id', 'The project UUID', required: true)
            ->withStringParameter('reason', 'Optional reason for pausing')
            ->using(function (string $project_id, ?string $reason = null) {
                $project = Project::find($project_id);
                if (! $project) {
                    return json_encode(['error' => 'Project not found']);
                }

                try {
                    app(PauseProjectAction::class)->execute($project, $reason);

                    return json_encode(['success' => true, 'message' => "Project '{$project->title}' paused."]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function resumeProject(): PrismToolObject
    {
        return PrismTool::as('resume_project')
            ->for('Resume a paused project and re-enable its schedule')
            ->withStringParameter('project_id', 'The project UUID', required: true)
            ->using(function (string $project_id) {
                $project = Project::find($project_id);
                if (! $project) {
                    return json_encode(['error' => 'Project not found']);
                }

                try {
                    app(ResumeProjectAction::class)->execute($project);

                    return json_encode(['success' => true, 'message' => "Project '{$project->title}' resumed."]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function pauseExperiment(): PrismToolObject
    {
        return PrismTool::as('pause_experiment')
            ->for('Pause a running experiment')
            ->withStringParameter('experiment_id', 'The experiment UUID', required: true)
            ->using(function (string $experiment_id) {
                $experiment = Experiment::find($experiment_id);
                if (! $experiment) {
                    return json_encode(['error' => 'Experiment not found']);
                }

                try {
                    app(PauseExperimentAction::class)->execute($experiment, auth()->id());

                    return json_encode(['success' => true, 'message' => "Experiment '{$experiment->title}' paused."]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function resumeExperiment(): PrismToolObject
    {
        return PrismTool::as('resume_experiment')
            ->for('Resume a paused experiment')
            ->withStringParameter('experiment_id', 'The experiment UUID', required: true)
            ->using(function (string $experiment_id) {
                $experiment = Experiment::find($experiment_id);
                if (! $experiment) {
                    return json_encode(['error' => 'Experiment not found']);
                }

                try {
                    app(ResumeExperimentAction::class)->execute($experiment, auth()->id());

                    return json_encode(['success' => true, 'message' => "Experiment '{$experiment->title}' resumed."]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function retryExperiment(): PrismToolObject
    {
        return PrismTool::as('retry_experiment')
            ->for('Retry a failed experiment')
            ->withStringParameter('experiment_id', 'The experiment UUID', required: true)
            ->using(function (string $experiment_id) {
                $experiment = Experiment::find($experiment_id);
                if (! $experiment) {
                    return json_encode(['error' => 'Experiment not found']);
                }

                try {
                    app(RetryExperimentAction::class)->execute($experiment, auth()->id());

                    return json_encode(['success' => true, 'message' => "Experiment '{$experiment->title}' retrying."]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function triggerProjectRun(): PrismToolObject
    {
        return PrismTool::as('trigger_project_run')
            ->for('Trigger a new run for a project')
            ->withStringParameter('project_id', 'The project UUID', required: true)
            ->using(function (string $project_id) {
                $project = Project::find($project_id);
                if (! $project) {
                    return json_encode(['error' => 'Project not found']);
                }

                try {
                    $run = app(TriggerProjectRunAction::class)->execute($project, 'assistant');

                    return json_encode([
                        'success' => true,
                        'run_id' => $run->id,
                        'message' => "Project run triggered for '{$project->title}'.",
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function approveRequest(): PrismToolObject
    {
        return PrismTool::as('approve_request')
            ->for('Approve a pending approval request')
            ->withStringParameter('approval_id', 'The approval request UUID', required: true)
            ->withStringParameter('notes', 'Optional approval notes')
            ->using(function (string $approval_id, ?string $notes = null) {
                $approval = ApprovalRequest::find($approval_id);
                if (! $approval) {
                    return json_encode(['error' => 'Approval request not found']);
                }

                try {
                    app(ApproveAction::class)->execute($approval, auth()->id(), $notes);

                    return json_encode(['success' => true, 'message' => 'Request approved.']);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function rejectRequest(): PrismToolObject
    {
        return PrismTool::as('reject_request')
            ->for('Reject a pending approval request')
            ->withStringParameter('approval_id', 'The approval request UUID', required: true)
            ->withStringParameter('reason', 'Reason for rejection', required: true)
            ->withStringParameter('notes', 'Optional rejection notes')
            ->using(function (string $approval_id, string $reason, ?string $notes = null) {
                $approval = ApprovalRequest::find($approval_id);
                if (! $approval) {
                    return json_encode(['error' => 'Approval request not found']);
                }

                try {
                    app(RejectAction::class)->execute($approval, auth()->id(), $reason, $notes);

                    return json_encode(['success' => true, 'message' => 'Request rejected.']);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function killExperiment(): PrismToolObject
    {
        return PrismTool::as('kill_experiment')
            ->for('Kill/terminate an experiment permanently. This is a destructive action.')
            ->withStringParameter('experiment_id', 'The experiment UUID', required: true)
            ->withStringParameter('reason', 'Reason for killing the experiment')
            ->using(function (string $experiment_id, ?string $reason = null) {
                $experiment = Experiment::find($experiment_id);
                if (! $experiment) {
                    return json_encode(['error' => 'Experiment not found']);
                }

                try {
                    app(KillExperimentAction::class)->execute($experiment, auth()->id(), $reason ?? 'Killed via assistant');

                    return json_encode(['success' => true, 'message' => "Experiment '{$experiment->title}' killed."]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function archiveProject(): PrismToolObject
    {
        return PrismTool::as('archive_project')
            ->for('Archive a project permanently. This is a destructive action.')
            ->withStringParameter('project_id', 'The project UUID', required: true)
            ->using(function (string $project_id) {
                $project = Project::find($project_id);
                if (! $project) {
                    return json_encode(['error' => 'Project not found']);
                }

                try {
                    app(ArchiveProjectAction::class)->execute($project);

                    return json_encode(['success' => true, 'message' => "Project '{$project->title}' archived."]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function activateProject(): PrismToolObject
    {
        return PrismTool::as('activate_project')
            ->for('Activate a draft or failed project so it can run. The project must be in draft or failed status.')
            ->withStringParameter('project_id', 'The project UUID', required: true)
            ->using(function (string $project_id) {
                $project = Project::find($project_id);
                if (! $project) {
                    return json_encode(['error' => 'Project not found']);
                }

                if (! $project->status->canTransitionTo(ProjectStatus::Active)) {
                    return json_encode(['error' => "Cannot activate project in '{$project->status->value}' status."]);
                }

                try {
                    DB::transaction(function () use ($project) {
                        $project->update(['status' => ProjectStatus::Active]);
                        if ($project->schedule) {
                            $project->schedule->update(['enabled' => true]);
                        }
                    });

                    $project->refresh();

                    return json_encode([
                        'success' => true,
                        'project_id' => $project->id,
                        'title' => $project->title,
                        'status' => $project->status->value,
                        'url' => route('projects.show', $project),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function startExperiment(): PrismToolObject
    {
        return PrismTool::as('start_experiment')
            ->for('Start a draft experiment, kicking off the AI pipeline (scoring → planning → building → executing). The experiment must be in draft status.')
            ->withStringParameter('experiment_id', 'The experiment UUID', required: true)
            ->using(function (string $experiment_id) {
                $experiment = Experiment::find($experiment_id);
                if (! $experiment) {
                    return json_encode(['error' => 'Experiment not found']);
                }

                if ($experiment->status !== ExperimentStatus::Draft) {
                    return json_encode(['error' => "Cannot start experiment in '{$experiment->status->value}' status. Only draft experiments can be started."]);
                }

                try {
                    $result = app(TransitionExperimentAction::class)->execute(
                        experiment: $experiment,
                        toState: ExperimentStatus::Scoring,
                        reason: 'Started via assistant',
                        actorId: auth()->id(),
                    );

                    return json_encode([
                        'success' => true,
                        'experiment_id' => $result->id,
                        'title' => $result->title,
                        'status' => $result->status->value,
                        'message' => "Experiment '{$result->title}' is now running.",
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function syncAgentSkills(): PrismToolObject
    {
        return PrismTool::as('sync_agent_skills')
            ->for('Attach or sync skills to an agent. Mode "sync" replaces all skills, "attach" adds skills, "detach" removes them.')
            ->withStringParameter('agent_id', 'The agent UUID', required: true)
            ->withStringParameter('skill_ids', 'Comma-separated skill UUIDs (or JSON array)', required: true)
            ->withStringParameter('mode', 'Operation: sync, attach, detach (default: sync)')
            ->using(function (string $agent_id, string $skill_ids, ?string $mode = null) {
                $agent = Agent::find($agent_id);
                if (! $agent) {
                    return json_encode(['error' => 'Agent not found']);
                }

                $ids = json_decode($skill_ids, true) ?? array_filter(array_map('trim', explode(',', $skill_ids)));
                $mode = in_array($mode, ['sync', 'attach', 'detach']) ? $mode : 'sync';

                try {
                    match ($mode) {
                        'sync' => $agent->skills()->sync($ids),
                        'attach' => $agent->skills()->syncWithoutDetaching($ids),
                        'detach' => $agent->skills()->detach($ids),
                    };

                    $agent->load('skills:id,name');

                    return json_encode([
                        'success' => true,
                        'agent_id' => $agent->id,
                        'mode' => $mode,
                        'attached_skill_count' => $agent->skills->count(),
                        'attached_skills' => $agent->skills->pluck('name')->toArray(),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function syncAgentTools(): PrismToolObject
    {
        return PrismTool::as('sync_agent_tools')
            ->for('Attach or sync tools to an agent. Mode "sync" replaces all tools, "attach" adds tools, "detach" removes them.')
            ->withStringParameter('agent_id', 'The agent UUID', required: true)
            ->withStringParameter('tool_ids', 'Comma-separated tool UUIDs (or JSON array)', required: true)
            ->withStringParameter('mode', 'Operation: sync, attach, detach (default: sync)')
            ->using(function (string $agent_id, string $tool_ids, ?string $mode = null) {
                $agent = Agent::find($agent_id);
                if (! $agent) {
                    return json_encode(['error' => 'Agent not found']);
                }

                $ids = json_decode($tool_ids, true) ?? array_filter(array_map('trim', explode(',', $tool_ids)));
                $mode = in_array($mode, ['sync', 'attach', 'detach']) ? $mode : 'sync';

                try {
                    match ($mode) {
                        'sync' => $agent->tools()->sync($ids),
                        'attach' => $agent->tools()->syncWithoutDetaching($ids),
                        'detach' => $agent->tools()->detach($ids),
                    };

                    $agent->load('tools:id,name');

                    return json_encode([
                        'success' => true,
                        'agent_id' => $agent->id,
                        'mode' => $mode,
                        'attached_tool_count' => $agent->tools->count(),
                        'attached_tools' => $agent->tools->pluck('name')->toArray(),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function deleteAgent(): PrismToolObject
    {
        return PrismTool::as('delete_agent')
            ->for('Soft-delete an AI agent. The agent must not have active experiments. This is a destructive action.')
            ->withStringParameter('agent_id', 'The agent UUID to delete', required: true)
            ->using(function (string $agent_id) {
                $agent = Agent::find($agent_id);
                if (! $agent) {
                    return json_encode(['error' => 'Agent not found']);
                }

                try {
                    $agentName = $agent->name;
                    $agent->delete();

                    return json_encode([
                        'success' => true,
                        'agent_id' => $agent_id,
                        'name' => $agentName,
                        'message' => "Agent '{$agentName}' has been deleted.",
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function deleteMemory(): PrismToolObject
    {
        return PrismTool::as('delete_memory')
            ->for('Delete one or more memory records by UUID. Only memories belonging to the current team can be deleted. This is destructive.')
            ->withStringParameter('memory_ids', 'Comma-separated memory UUIDs (or JSON array) to delete', required: true)
            ->using(function (string $memory_ids) {
                $ids = json_decode($memory_ids, true) ?? array_filter(array_map('trim', explode(',', $memory_ids)));
                $teamId = auth()->user()?->current_team_id;

                if (! $teamId) {
                    return json_encode(['error' => 'No current team.']);
                }

                try {
                    $deleted = Memory::withoutGlobalScopes()
                        ->where('team_id', $teamId)
                        ->whereIn('id', $ids)
                        ->delete();

                    return json_encode([
                        'success' => true,
                        'deleted_count' => $deleted,
                        'message' => "{$deleted} memory record(s) deleted.",
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function uploadMemoryKnowledge(): PrismToolObject
    {
        return PrismTool::as('upload_memory_knowledge')
            ->for('Store a new knowledge item in memory. Useful for injecting domain knowledge or reference material that agents can recall.')
            ->withStringParameter('content', 'The knowledge content to store', required: true)
            ->withStringParameter('agent_id', 'Optional agent UUID to associate this memory with')
            ->withStringParameter('source_type', 'Source category label (default: manual_upload)')
            ->using(function (string $content, ?string $agent_id = null, ?string $source_type = null) {
                $teamId = auth()->user()?->current_team_id;

                if (! $teamId) {
                    return json_encode(['error' => 'No current team.']);
                }

                try {
                    $memory = Memory::create([
                        'team_id' => $teamId,
                        'agent_id' => $agent_id,
                        'content' => trim($content),
                        'source_type' => $source_type ?? 'manual_upload',
                        'metadata' => ['uploaded_by' => auth()->id(), 'uploaded_at' => now()->toIso8601String()],
                    ]);

                    return json_encode([
                        'success' => true,
                        'memory_id' => $memory->id,
                        'source_type' => $memory->source_type,
                        'content_length' => strlen($memory->content),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function rejectEvolutionProposal(): PrismToolObject
    {
        return PrismTool::as('reject_evolution_proposal')
            ->for('Reject a pending or approved evolution proposal, preventing it from being applied to the agent.')
            ->withStringParameter('proposal_id', 'The evolution proposal UUID', required: true)
            ->withStringParameter('reason', 'Optional reason for rejection')
            ->using(function (string $proposal_id, ?string $reason = null) {
                $proposal = EvolutionProposal::find($proposal_id);
                if (! $proposal) {
                    return json_encode(['error' => 'Evolution proposal not found']);
                }

                if (! in_array($proposal->status, [EvolutionProposalStatus::Pending, EvolutionProposalStatus::Approved])) {
                    return json_encode(['error' => "Cannot reject proposal in '{$proposal->status->value}' status."]);
                }

                try {
                    $proposal->update([
                        'status' => EvolutionProposalStatus::Rejected,
                        'reviewed_by' => auth()->id(),
                        'reviewed_at' => now(),
                    ]);

                    return json_encode([
                        'success' => true,
                        'proposal_id' => $proposal->id,
                        'status' => 'rejected',
                        'reason' => $reason,
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function deleteConnectorBinding(): PrismToolObject
    {
        return PrismTool::as('delete_connector_binding')
            ->for('Delete a connector binding (DM pairing / sender approval). This will prevent the sender from communicating via this channel. Destructive.')
            ->withStringParameter('binding_id', 'The connector binding UUID', required: true)
            ->using(function (string $binding_id) {
                $teamId = auth()->user()?->current_team_id;

                if (! $teamId) {
                    return json_encode(['error' => 'No current team.']);
                }

                $binding = ConnectorBinding::withoutGlobalScopes()
                    ->where('team_id', $teamId)
                    ->find($binding_id);

                if (! $binding) {
                    return json_encode(['error' => 'Connector binding not found.']);
                }

                try {
                    $channel = $binding->channel;
                    $externalName = $binding->external_name ?? $binding->external_id;
                    $binding->delete();

                    return json_encode([
                        'success' => true,
                        'binding_id' => $binding_id,
                        'message' => "Binding for '{$externalName}' on {$channel} deleted.",
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function manageByokCredential(): PrismToolObject
    {
        return PrismTool::as('manage_byok_credential')
            ->for('Manage BYOK (Bring Your Own Key) LLM API credentials. List configured providers, set an API key, or delete a provider key. SECURITY: API keys are never returned after storage.')
            ->withStringParameter('action', 'Action: list, set, delete', required: true)
            ->withStringParameter('provider', 'LLM provider name (e.g. anthropic, openai, google). Required for set/delete.')
            ->withStringParameter('api_key', 'The API key to store securely (for set action only). Will be encrypted.')
            ->using(function (string $action, ?string $provider = null, ?string $api_key = null) {
                $teamId = auth()->user()?->current_team_id;

                if (! $teamId) {
                    return json_encode(['error' => 'No current team.']);
                }

                if ($action === 'list') {
                    $creds = TeamProviderCredential::where('team_id', $teamId)
                        ->get(['id', 'provider', 'is_active', 'updated_at'])
                        ->map(fn ($c) => [
                            'provider' => $c->provider,
                            'is_active' => $c->is_active,
                            'configured_at' => $c->updated_at?->toIso8601String(),
                            'note' => 'API key stored encrypted, cannot be retrieved.',
                        ]);

                    return json_encode(['providers' => $creds->toArray()]);
                }

                if ($action === 'set') {
                    if (! $provider || ! $api_key) {
                        return json_encode(['error' => 'provider and api_key are required for set action.']);
                    }

                    TeamProviderCredential::updateOrCreate(
                        ['team_id' => $teamId, 'provider' => $provider],
                        ['credentials' => ['api_key' => $api_key], 'is_active' => true],
                    );

                    $masked = strlen($api_key) > 8
                        ? str_repeat('*', strlen($api_key) - 4).substr($api_key, -4)
                        : '****';

                    return json_encode([
                        'success' => true,
                        'provider' => $provider,
                        'masked_key' => $masked,
                        'message' => "API key for '{$provider}' stored securely. Will not be shown again.",
                    ]);
                }

                if ($action === 'delete') {
                    if (! $provider) {
                        return json_encode(['error' => 'provider is required for delete action.']);
                    }

                    $deleted = TeamProviderCredential::where('team_id', $teamId)
                        ->where('provider', $provider)
                        ->delete();

                    if (! $deleted) {
                        return json_encode(['error' => "No credential found for provider '{$provider}'."]);
                    }

                    return json_encode(['success' => true, 'provider' => $provider, 'message' => "API key for '{$provider}' deleted."]);
                }

                return json_encode(['error' => "Unknown action: {$action}. Use list, set, or delete."]);
            });
    }

    private static function manageApiToken(): PrismToolObject
    {
        return PrismTool::as('manage_api_token')
            ->for('Manage Sanctum API tokens for the current user. List tokens, create a new one (shown ONCE), or revoke by ID. Destructive for revoke.')
            ->withStringParameter('action', 'Action: list, create, revoke', required: true)
            ->withStringParameter('name', 'Token name/label (required for create)')
            ->withStringParameter('token_id', 'Token ID to revoke (required for revoke action)')
            ->using(function (string $action, ?string $name = null, ?string $token_id = null) {
                $user = auth()->user();

                if (! $user) {
                    return json_encode(['error' => 'Not authenticated.']);
                }

                if ($action === 'list') {
                    $tokens = $user->tokens()
                        ->get(['id', 'name', 'last_used_at', 'expires_at', 'created_at'])
                        ->map(fn ($t) => [
                            'id' => $t->id,
                            'name' => $t->name,
                            'last_used_at' => $t->last_used_at?->toIso8601String(),
                            'expires_at' => $t->expires_at?->toIso8601String(),
                            'created_at' => $t->created_at->toIso8601String(),
                        ]);

                    return json_encode(['count' => $tokens->count(), 'tokens' => $tokens->toArray()]);
                }

                if ($action === 'create') {
                    if (! $name) {
                        return json_encode(['error' => 'name is required for create action.']);
                    }

                    $abilities = $user->is_super_admin ? ['*'] : ['team:'.$user->current_team_id];
                    $expiresAt = now()->addDays(90);
                    $token = SanctumTokenIssuer::create($user, $name, $abilities, $expiresAt);

                    return json_encode([
                        'success' => true,
                        'token_id' => $token->accessToken->id,
                        'name' => $name,
                        'token' => $token->plainTextToken,
                        'expires_at' => $expiresAt->toIso8601String(),
                        'warning' => 'This token will not be shown again. Store it securely.',
                    ]);
                }

                if ($action === 'revoke') {
                    if (! $token_id) {
                        return json_encode(['error' => 'token_id is required for revoke action.']);
                    }

                    $deleted = $user->tokens()->where('id', $token_id)->delete();

                    if (! $deleted) {
                        return json_encode(['error' => "Token {$token_id} not found."]);
                    }

                    return json_encode(['success' => true, 'token_id' => $token_id, 'message' => "Token {$token_id} revoked."]);
                }

                return json_encode(['error' => "Unknown action: {$action}. Use list, create, or revoke."]);
            });
    }

    private static function updateGlobalSettings(): PrismToolObject
    {
        $allowedKeys = [
            'assistant_llm_provider', 'assistant_llm_model',
            'default_llm_provider', 'default_llm_model',
            'budget_cap_credits', 'rate_limit_rpm',
            'outbound_rate_limit', 'experiment_timeout_seconds',
            'weekly_digest_enabled', 'audit_retention_days',
        ];

        return PrismTool::as('update_global_settings')
            ->for('Update global platform settings. Allowed keys: '.implode(', ', $allowedKeys).'. Returns previous and new values.')
            ->withStringParameter('settings_json', 'JSON object of setting key-value pairs to update. Example: {"default_llm_provider":"anthropic","budget_cap_credits":50000}', required: true)
            ->using(function (string $settings_json) use ($allowedKeys) {
                $settings = json_decode($settings_json, true);

                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($settings)) {
                    return json_encode(['error' => 'Invalid JSON: '.json_last_error_msg()]);
                }

                $unknownKeys = array_diff(array_keys($settings), $allowedKeys);
                if (! empty($unknownKeys)) {
                    return json_encode(['error' => 'Unknown keys: '.implode(', ', $unknownKeys).'. Allowed: '.implode(', ', $allowedKeys)]);
                }

                $updated = [];
                foreach ($settings as $key => $value) {
                    $previous = GlobalSetting::get($key);
                    GlobalSetting::set($key, $value);
                    $updated[$key] = ['previous' => $previous, 'new' => $value];
                }

                return json_encode(['success' => true, 'updated_count' => count($updated), 'changes' => $updated]);
            });
    }

    private static function createEmailTemplate(): PrismToolObject
    {
        return PrismTool::as('create_email_template')
            ->for('Create a new email template. Provide html_body (raw HTML) or mjml_body (MJML markup — compiled server-side to HTML). After creation, visit the builder URL to refine visually.')
            ->withStringParameter('name', 'Template name', required: true)
            ->withStringParameter('subject', 'Email subject line. Supports merge tags like {{first_name}}')
            ->withStringParameter('preview_text', 'Short inbox preview text (50–90 characters)')
            ->withStringParameter('html_body', 'Raw HTML content for the email body')
            ->withStringParameter('mjml_body', 'Complete MJML document (<mjml>...</mjml>). Compiled automatically to cross-client HTML. Preferred over html_body.')
            ->withStringParameter('status', 'Status: draft, active, archived (default: draft)')
            ->withStringParameter('visibility', 'Visibility: private, public (default: private)')
            ->withStringParameter('email_theme_id', 'UUID of the email theme to apply')
            ->using(function (
                string $name,
                ?string $subject = null,
                ?string $preview_text = null,
                ?string $html_body = null,
                ?string $mjml_body = null,
                ?string $status = null,
                ?string $visibility = null,
                ?string $email_theme_id = null,
            ) {
                try {
                    $team = auth()->user()->currentTeam;

                    // Sanitize LLM "None" strings for optional UUID fields
                    $email_theme_id = ($email_theme_id && Str::isUuid($email_theme_id)) ? $email_theme_id : null;

                    $data = array_filter([
                        'name' => $name,
                        'subject' => $subject,
                        'preview_text' => $preview_text,
                        'status' => $status ?? 'draft',
                        'visibility' => $visibility ?? 'private',
                        'email_theme_id' => $email_theme_id,
                    ], fn ($v) => $v !== null);

                    if ($mjml_body !== null) {
                        $data['html_cache'] = app(MjmlRenderer::class)->render($mjml_body);
                        $data['design_json'] = ['type' => 'mjml', 'source' => $mjml_body];
                    } elseif ($html_body !== null) {
                        $data['html_cache'] = $html_body;
                    }

                    $template = app(CreateEmailTemplateAction::class)->execute($team, $data);

                    return json_encode([
                        'success' => true,
                        'template_id' => $template->id,
                        'name' => $template->name,
                        'status' => $template->status->value,
                        'has_html_cache' => ! empty($template->html_cache),
                        'url' => route('email.templates.edit', $template),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function updateEmailTemplate(): PrismToolObject
    {
        return PrismTool::as('update_email_template')
            ->for('Update an existing email template metadata or body content. Provide html_body or mjml_body to set HTML content. Only supply fields you want to change — omitted fields are preserved.')
            ->withStringParameter('template_id', 'Email template UUID', required: true)
            ->withStringParameter('name', 'Template name')
            ->withStringParameter('subject', 'Email subject line. Supports merge tags like {{first_name}}')
            ->withStringParameter('preview_text', 'Short inbox preview text (50–90 characters)')
            ->withStringParameter('html_body', 'Raw HTML content for the email body')
            ->withStringParameter('mjml_body', 'Complete MJML document (<mjml>...</mjml>). Compiled automatically to cross-client HTML. Preferred over html_body.')
            ->withStringParameter('status', 'Status: draft, active, archived')
            ->withStringParameter('visibility', 'Visibility: private, public')
            ->withStringParameter('email_theme_id', 'UUID of the email theme to apply')
            ->using(function (
                string $template_id,
                ?string $name = null,
                ?string $subject = null,
                ?string $preview_text = null,
                ?string $html_body = null,
                ?string $mjml_body = null,
                ?string $status = null,
                ?string $visibility = null,
                ?string $email_theme_id = null,
            ) {
                $template = EmailTemplate::find($template_id);
                if (! $template) {
                    return json_encode(['error' => 'Email template not found']);
                }

                try {
                    // Sanitize LLM "None" strings for optional UUID fields
                    $email_theme_id = ($email_theme_id && Str::isUuid($email_theme_id)) ? $email_theme_id : null;

                    $data = array_filter([
                        'name' => $name,
                        'subject' => $subject,
                        'preview_text' => $preview_text,
                        'status' => $status,
                        'visibility' => $visibility,
                        'email_theme_id' => $email_theme_id,
                    ], fn ($v) => $v !== null);

                    if ($mjml_body !== null) {
                        $data['html_cache'] = app(MjmlRenderer::class)->render($mjml_body);
                        $data['design_json'] = ['type' => 'mjml', 'source' => $mjml_body];
                    } elseif ($html_body !== null) {
                        $data['html_cache'] = $html_body;
                    }

                    $template = app(UpdateEmailTemplateAction::class)->execute($template, $data);

                    return json_encode([
                        'success' => true,
                        'template_id' => $template->id,
                        'name' => $template->name,
                        'status' => $template->status->value,
                        'has_html_cache' => ! empty($template->html_cache),
                        'url' => route('email.templates.edit', $template),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function deleteEmailTemplate(): PrismToolObject
    {
        return PrismTool::as('delete_email_template')
            ->for('Delete an email template (soft delete). This is a destructive action — the template will be permanently removed from the list.')
            ->withStringParameter('template_id', 'Email template UUID', required: true)
            ->using(function (string $template_id) {
                $template = EmailTemplate::find($template_id);
                if (! $template) {
                    return json_encode(['error' => 'Email template not found']);
                }

                try {
                    $name = $template->name;
                    app(DeleteEmailTemplateAction::class)->execute($template);

                    return json_encode(['success' => true, 'message' => "Email template '{$name}' deleted."]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }
}
