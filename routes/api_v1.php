<?php

use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\ApprovalController;
use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BudgetController;
use App\Http\Controllers\Api\V1\CredentialController;
use App\Http\Controllers\Api\V1\CrewController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ExperimentController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MarketplaceController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\SignalController;
use App\Http\Controllers\Api\V1\SkillController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\ToolController;
use App\Http\Controllers\Api\V1\WorkflowController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API V1 Routes
|--------------------------------------------------------------------------
|
| Versioned API endpoints for mobile/desktop clients.
| All authenticated routes require a Sanctum bearer token.
|
*/

// Auth (public — no auth required)
Route::post('/auth/token', [AuthController::class, 'token']);

// Authenticated routes
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

    // Auth management
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);
    Route::delete('/auth/token', [AuthController::class, 'logout']);
    Route::get('/auth/devices', [AuthController::class, 'devices']);
    Route::delete('/auth/devices/{tokenId}', [AuthController::class, 'revokeDevice']);

    // Current user
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me', [AuthController::class, 'updateMe']);

    // Experiments
    Route::get('/experiments', [ExperimentController::class, 'index']);
    Route::get('/experiments/{experiment}', [ExperimentController::class, 'show']);
    Route::post('/experiments', [ExperimentController::class, 'store']);
    Route::post('/experiments/{experiment}/transition', [ExperimentController::class, 'transition']);
    Route::post('/experiments/{experiment}/pause', [ExperimentController::class, 'pause']);
    Route::post('/experiments/{experiment}/resume', [ExperimentController::class, 'resume']);
    Route::post('/experiments/{experiment}/retry', [ExperimentController::class, 'retry']);
    Route::post('/experiments/{experiment}/kill', [ExperimentController::class, 'kill']);
    Route::post('/experiments/{experiment}/retry-from-step', [ExperimentController::class, 'retryFromStep']);
    Route::get('/experiments/{experiment}/steps', [ExperimentController::class, 'steps']);

    // Agents
    Route::apiResource('agents', AgentController::class);
    Route::patch('/agents/{agent}/status', [AgentController::class, 'toggleStatus']);

    // Skills
    Route::apiResource('skills', SkillController::class);
    Route::get('/skills/{skill}/versions', [SkillController::class, 'versions']);

    // Tools
    Route::apiResource('tools', ToolController::class);

    // Credentials
    Route::apiResource('credentials', CredentialController::class);
    Route::post('/credentials/{credential}/rotate', [CredentialController::class, 'rotate']);

    // Projects
    Route::apiResource('projects', ProjectController::class);
    Route::post('/projects/{project}/activate', [ProjectController::class, 'activate']);
    Route::post('/projects/{project}/pause', [ProjectController::class, 'pause']);
    Route::post('/projects/{project}/resume', [ProjectController::class, 'resume']);
    Route::post('/projects/{project}/restart', [ProjectController::class, 'restart']);
    Route::post('/projects/{project}/trigger', [ProjectController::class, 'triggerRun']);
    Route::get('/projects/{project}/runs', [ProjectController::class, 'runs']);

    // Signals
    Route::get('/signals', [SignalController::class, 'index']);
    Route::get('/signals/{signal}', [SignalController::class, 'show']);
    Route::post('/signals', [SignalController::class, 'store']);

    // Approvals
    Route::get('/approvals', [ApprovalController::class, 'index']);
    Route::get('/approvals/{approval}', [ApprovalController::class, 'show']);
    Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve']);
    Route::post('/approvals/{approval}/reject', [ApprovalController::class, 'reject']);

    // Workflows
    Route::apiResource('workflows', WorkflowController::class);
    Route::put('/workflows/{workflow}/graph', [WorkflowController::class, 'saveGraph']);
    Route::post('/workflows/{workflow}/validate', [WorkflowController::class, 'validateGraph']);
    Route::post('/workflows/{workflow}/activate', [WorkflowController::class, 'activate']);
    Route::post('/workflows/{workflow}/duplicate', [WorkflowController::class, 'duplicate']);
    Route::get('/workflows/{workflow}/cost', [WorkflowController::class, 'estimateCost']);

    // Crews
    Route::apiResource('crews', CrewController::class);
    Route::post('/crews/{crew}/execute', [CrewController::class, 'execute']);
    Route::get('/crews/{crew}/executions', [CrewController::class, 'executions']);
    Route::get('/crews/{crew}/executions/{execution}', [CrewController::class, 'showExecution']);

    // Marketplace
    Route::get('/marketplace', [MarketplaceController::class, 'index']);
    Route::get('/marketplace/{listing:slug}', [MarketplaceController::class, 'show']);
    Route::post('/marketplace', [MarketplaceController::class, 'publish']);
    Route::post('/marketplace/{listing:slug}/install', [MarketplaceController::class, 'install']);
    Route::post('/marketplace/{listing:slug}/reviews', [MarketplaceController::class, 'review']);

    // Team
    Route::get('/team', [TeamController::class, 'show']);
    Route::put('/team', [TeamController::class, 'update']);
    Route::get('/team/members', [TeamController::class, 'members']);
    Route::delete('/team/members/{userId}', [TeamController::class, 'removeMember']);
    Route::get('/team/credentials', [TeamController::class, 'credentials']);
    Route::post('/team/credentials', [TeamController::class, 'storeCredential']);
    Route::delete('/team/credentials/{credential}', [TeamController::class, 'deleteCredential']);
    Route::get('/team/tokens', [TeamController::class, 'tokens']);
    Route::post('/team/tokens', [TeamController::class, 'createToken']);
    Route::delete('/team/tokens/{tokenId}', [TeamController::class, 'revokeToken']);

    // Dashboard & Health
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/health', [HealthController::class, 'index']);

    // Audit
    Route::get('/audit', [AuditController::class, 'index']);

    // Budget
    Route::get('/budget', [BudgetController::class, 'index']);
});
