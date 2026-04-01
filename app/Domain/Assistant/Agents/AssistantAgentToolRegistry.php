<?php

namespace App\Domain\Assistant\Agents;

use App\Domain\Assistant\AgentTools\ActivateProjectTool;
use App\Domain\Assistant\AgentTools\ActivateWorkflowTool;
use App\Domain\Assistant\AgentTools\AddAgentToCrewTool;
use App\Domain\Assistant\AgentTools\ApproveRequestTool;
use App\Domain\Assistant\AgentTools\ArchiveProjectTool;
use App\Domain\Assistant\AgentTools\CreateAgentTool;
use App\Domain\Assistant\AgentTools\CreateCrewTool;
use App\Domain\Assistant\AgentTools\CreateEmailTemplateTool;
use App\Domain\Assistant\AgentTools\CreateExperimentTool;
use App\Domain\Assistant\AgentTools\CreateProjectTool;
use App\Domain\Assistant\AgentTools\CreateSkillTool;
use App\Domain\Assistant\AgentTools\CreateWorkflowTool;
use App\Domain\Assistant\AgentTools\DelegateAndNotifyTool;
use App\Domain\Assistant\AgentTools\DeleteAgentTool;
use App\Domain\Assistant\AgentTools\DeleteConnectorBindingTool;
use App\Domain\Assistant\AgentTools\DeleteEmailTemplateTool;
use App\Domain\Assistant\AgentTools\DeleteMemoryTool;
use App\Domain\Assistant\AgentTools\DesignCrewTool;
use App\Domain\Assistant\AgentTools\ExecuteCrewTool;
use App\Domain\Assistant\AgentTools\GenerateWorkflowTool;
use App\Domain\Assistant\AgentTools\GetAgentTool;
use App\Domain\Assistant\AgentTools\GetBudgetSummaryTool;
use App\Domain\Assistant\AgentTools\GetContactRiskScoreTool;
use App\Domain\Assistant\AgentTools\GetCrewTool;
use App\Domain\Assistant\AgentTools\GetDashboardKpisTool;
use App\Domain\Assistant\AgentTools\GetDelegationResultsTool;
use App\Domain\Assistant\AgentTools\GetExperimentTool;
use App\Domain\Assistant\AgentTools\GetMemoryStatsTool;
use App\Domain\Assistant\AgentTools\GetProjectTool;
use App\Domain\Assistant\AgentTools\GetSystemHealthTool;
use App\Domain\Assistant\AgentTools\GetWorkflowTool;
use App\Domain\Assistant\AgentTools\KillExperimentTool;
use App\Domain\Assistant\AgentTools\ListAgentsTool;
use App\Domain\Assistant\AgentTools\ListCrewsTool;
use App\Domain\Assistant\AgentTools\ListEmailTemplatesTool;
use App\Domain\Assistant\AgentTools\ListEmailThemesTool;
use App\Domain\Assistant\AgentTools\ListExperimentsTool;
use App\Domain\Assistant\AgentTools\ListHighRiskContactsTool;
use App\Domain\Assistant\AgentTools\ListPendingApprovalsTool;
use App\Domain\Assistant\AgentTools\ListProjectsTool;
use App\Domain\Assistant\AgentTools\ListRecentMemoriesTool;
use App\Domain\Assistant\AgentTools\ListSkillsTool;
use App\Domain\Assistant\AgentTools\ListWorkflowsTool;
use App\Domain\Assistant\AgentTools\ManageApiTokenTool;
use App\Domain\Assistant\AgentTools\ManageByokCredentialTool;
use App\Domain\Assistant\AgentTools\PauseExperimentTool;
use App\Domain\Assistant\AgentTools\PauseProjectTool;
use App\Domain\Assistant\AgentTools\RejectEvolutionProposalTool;
use App\Domain\Assistant\AgentTools\RejectRequestTool;
use App\Domain\Assistant\AgentTools\ResumeExperimentTool;
use App\Domain\Assistant\AgentTools\ResumeProjectTool;
use App\Domain\Assistant\AgentTools\RetryExperimentTool;
use App\Domain\Assistant\AgentTools\SaveWorkflowGraphTool;
use App\Domain\Assistant\AgentTools\ScheduleProjectTool;
use App\Domain\Assistant\AgentTools\SearchExperimentHistoryTool;
use App\Domain\Assistant\AgentTools\SearchMemoriesTool;
use App\Domain\Assistant\AgentTools\StartExperimentTool;
use App\Domain\Assistant\AgentTools\SyncAgentSkillsTool;
use App\Domain\Assistant\AgentTools\SyncAgentToolsTool;
use App\Domain\Assistant\AgentTools\TriggerProjectRunTool;
use App\Domain\Assistant\AgentTools\UpdateEmailTemplateTool;
use App\Domain\Assistant\AgentTools\UpdateGlobalSettingsTool;
use App\Domain\Assistant\AgentTools\UpdateProjectTool;
use App\Domain\Assistant\AgentTools\UpdateSkillTool;
use App\Domain\Assistant\AgentTools\UploadMemoryKnowledgeTool;
use App\Models\User;
use Laravel\Ai\Contracts\Tool;

class AssistantAgentToolRegistry
{
    /**
     * @return array<Tool>
     */
    public function getTools(?User $user): array
    {
        $role = $user?->teamRole($user->currentTeam)?->value ?? 'viewer';

        $tools = $this->readTools();

        if (in_array($role, ['member', 'admin', 'owner'])) {
            $tools = array_merge($tools, $this->writeTools());
        }

        if (in_array($role, ['admin', 'owner'])) {
            $tools = array_merge($tools, $this->destructiveTools());
        }

        return $tools;
    }

    /**
     * @return array<Tool>
     */
    private function readTools(): array
    {
        return [
            new ListProjectsTool,
            new ListExperimentsTool,
            new ListAgentsTool,
            new ListSkillsTool,
            new ListCrewsTool,
            new ListWorkflowsTool,
            new ListPendingApprovalsTool,
            new ListEmailTemplatesTool,
            new ListEmailThemesTool,
            new GetExperimentTool,
            new GetProjectTool,
            new GetAgentTool,
            new GetCrewTool,
            new GetWorkflowTool,
            new GetDashboardKpisTool,
            new GetSystemHealthTool,
            new GetBudgetSummaryTool,
            new GetMemoryStatsTool,
            new GetContactRiskScoreTool,
            new ListHighRiskContactsTool,
            new SearchMemoriesTool,
            new ListRecentMemoriesTool,
            new SearchExperimentHistoryTool,
            new GetDelegationResultsTool,
        ];
    }

    /**
     * @return array<Tool>
     */
    private function writeTools(): array
    {
        return [
            new CreateProjectTool,
            new UpdateProjectTool,
            new ActivateProjectTool,
            new PauseProjectTool,
            new ResumeProjectTool,
            new TriggerProjectRunTool,
            new ScheduleProjectTool,
            new CreateAgentTool,
            new SyncAgentSkillsTool,
            new SyncAgentToolsTool,
            new CreateCrewTool,
            new AddAgentToCrewTool,
            new DesignCrewTool,
            new ExecuteCrewTool,
            new CreateSkillTool,
            new UpdateSkillTool,
            new CreateWorkflowTool,
            new GenerateWorkflowTool,
            new SaveWorkflowGraphTool,
            new ActivateWorkflowTool,
            new CreateExperimentTool,
            new StartExperimentTool,
            new PauseExperimentTool,
            new ResumeExperimentTool,
            new RetryExperimentTool,
            new ApproveRequestTool,
            new RejectRequestTool,
            new CreateEmailTemplateTool,
            new UpdateEmailTemplateTool,
            new UploadMemoryKnowledgeTool,
            new DelegateAndNotifyTool,
            new ManageByokCredentialTool,
            new ManageApiTokenTool,
            new RejectEvolutionProposalTool,
        ];
    }

    /**
     * @return array<Tool>
     */
    private function destructiveTools(): array
    {
        return [
            new KillExperimentTool,
            new ArchiveProjectTool,
            new DeleteAgentTool,
            new DeleteMemoryTool,
            new DeleteConnectorBindingTool,
            new DeleteEmailTemplateTool,
            new UpdateGlobalSettingsTool,
        ];
    }
}
