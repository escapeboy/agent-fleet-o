<?php

namespace App\Providers;

use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Audit\Listeners\LogExperimentTransition;
use App\Domain\Budget\Listeners\PauseOnBudgetExceeded;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Listeners\CheckParentExperimentCompletion;
use App\Domain\Experiment\Listeners\CollectWorkflowArtifactsOnCompletion;
use App\Domain\Experiment\Listeners\DispatchNextStageJob;
use App\Domain\Experiment\Listeners\NotifyOnCriticalTransition;
use App\Domain\Experiment\Listeners\RecordTransitionMetrics;
use App\Domain\Experiment\Listeners\ResumeParentOnSubWorkflowComplete;
use App\Domain\Memory\Listeners\StoreExecutionMemory;
use App\Domain\Memory\Listeners\StoreExperimentLearnings;
use App\Domain\Metrics\Jobs\EvaluateExecutionJob;
use App\Domain\Project\Listeners\LogProjectActivity;
use App\Domain\Project\Listeners\NotifyAssistantOnProjectComplete;
use App\Domain\Project\Listeners\NotifyDependentsOnRunComplete;
use App\Domain\Project\Listeners\SyncProjectStatusOnRunComplete;
use App\Domain\Shared\Services\DeploymentMode;
use App\Domain\Skill\Models\SkillExecution;
use App\Domain\Webhook\Listeners\SendWebhookOnExperimentTransition;
use App\Domain\Webhook\Listeners\SendWebhookOnProjectRunComplete;
use App\Infrastructure\Bridge\HandleBridgeRelayResponse;
use App\Infrastructure\Mail\TeamAwareMailChannel;
use Dedoc\Scramble\Generator;