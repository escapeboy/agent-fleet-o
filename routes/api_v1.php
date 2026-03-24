<?php

use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\ApprovalController;
use App\Http\Controllers\Api\V1\ArtifactController;
use App\Http\Controllers\Api\V1\AssistantController;
use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BridgeController;
use App\Http\Controllers\Api\V1\BudgetController;
use App\Http\Controllers\Api\V1\ChatbotInstanceController;
use App\Http\Controllers\Api\V1\CredentialController;
use App\Http\Controllers\Api\V1\CrewController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EmailTemplateController;
use App\Http\Controllers\Api\V1\EmailThemeController;
use App\Http\Controllers\Api\V1\EvolutionController;
use App\Http\Controllers\Api\V1\ExperimentController;
use App\Http\Controllers\Api\V1\GitRepositoryController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\KnowledgeBaseController;
use App\Http\Controllers\Api\V1\KnowledgeGraphController;
use App\Http\Controllers\Api\V1\MarketplaceController;
use App\Http\Controllers\Api\V1\MemoryController;
use App\Http\Controllers\Api\V1\MetricsController;
use App\Http\Controllers\Api\V1\OutboundConnectorConfigController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProviderConfigController;
use App\Http\Controllers\Api\V1\SignalController;
use App\Http\Controllers\Api\V1\SkillController;
use App\Http\Controllers\Api\V1\SshFingerprintController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\ToolController;
use App\Http\Controllers\Api\V1\TriggerController;
use App\Http\Controllers\Api\V1\WebhookEndpointController;
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

// Public endpoints (no auth required)
Route::post('/auth/token', [AuthController::class, 'token'])->middleware('throttle:5,1');
Route::get('/health', [HealthController::class, 'index']);

// Public marketplace API (no auth, rate-limited)
Route::prefix('marketplace')
    ->middleware('throttle:marketplace-public')
    ->group(function () {
        Route::get('/listings', [MarketplaceController::class, 'index']);
        Route::get('/listings/{listing:slug}', [MarketplaceController::class, 'show']);
        Route::get('/listings/{listing:slug}/download', [MarketplaceController::class, 'download']);
        Route::get('/listings/{listing:slug}/reviews', [MarketplaceController::class, 'reviews']);
        Route::get('/categories', [MarketplaceController::class, 'categories']);
    });

// Authenticated routes
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

    // Auth management (refresh has tighter rate limit to prevent token churn abuse)
    Route::post('/auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:10,1');
    Route::delete('/auth/token', [AuthController::class, 'logout']);
    Route::get('/auth/devices', [AuthController::class, 'devices']);
    Route::delete('/auth/devices/{tokenId}', [AuthController::class, 'revokeDevice']);

    // Current user
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me', [AuthController::class, 'updateMe']);
    Route::get('/me/social-accounts', [AuthController::class, 'socialAccounts']);
    Route::delete('/me/social-accounts/{provider}', [AuthController::class, 'unlinkSocialAccount']);

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
    Route::get('/experiments/{experiment}/snapshots', [ExperimentController::class, 'snapshots']);

    // Agents
    Route::apiResource('agents', AgentController::class);
    Route::patch('/agents/{agent}/status', [AgentController::class, 'toggleStatus']);
    Route::get('/agents/{agent}/config-history', [AgentController::class, 'configHistory']);
    Route::post('/agents/{agent}/rollback', [AgentController::class, 'rollback']);
    Route::get('/agents/{agent}/runtime-state', [AgentController::class, 'runtimeState']);
    Route::post('/agents/{agent}/runtime-state/reset-session', [AgentController::class, 'resetRuntimeSession']);
    Route::post('/agents/{agent}/feedback', [AgentController::class, 'submitFeedback']);
    Route::get('/agents/{agent}/feedback', [AgentController::class, 'listFeedback']);
    Route::get('/agents/{agent}/feedback/stats', [AgentController::class, 'feedbackStats']);

    // Skills
    Route::apiResource('skills', SkillController::class);
    Route::get('/skills/{skill}/versions', [SkillController::class, 'versions']);

    // Tools
    Route::apiResource('tools', ToolController::class);
    Route::get('/ssh-fingerprints', [SshFingerprintController::class, 'index']);
    Route::delete('/ssh-fingerprints/{sshFingerprint}', [SshFingerprintController::class, 'destroy']);

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
    Route::post('/approvals/{approval}/complete-human-task', [ApprovalController::class, 'completeHumanTask']);
    Route::post('/approvals/{approval}/escalate', [ApprovalController::class, 'escalate']);

    // Workflows
    Route::apiResource('workflows', WorkflowController::class);
    Route::put('/workflows/{workflow}/graph', [WorkflowController::class, 'saveGraph']);
    Route::post('/workflows/{workflow}/validate', [WorkflowController::class, 'validateGraph']);
    Route::post('/workflows/{workflow}/activate', [WorkflowController::class, 'activate']);
    Route::post('/workflows/{workflow}/duplicate', [WorkflowController::class, 'duplicate']);
    Route::get('/workflows/{workflow}/cost', [WorkflowController::class, 'estimateCost']);
    Route::get('/workflows/{workflow}/export', [WorkflowController::class, 'export']);
    Route::post('/workflows/import', [WorkflowController::class, 'import']);

    // Crews
    Route::apiResource('crews', CrewController::class);
    Route::post('/crews/{crew}/execute', [CrewController::class, 'execute']);
    Route::get('/crews/{crew}/executions', [CrewController::class, 'executions']);
    Route::get('/crews/{crew}/executions/{execution}', [CrewController::class, 'showExecution']);

    // Artifacts
    Route::get('/artifacts', [ArtifactController::class, 'index']);
    Route::get('/artifacts/{artifact}', [ArtifactController::class, 'show']);
    Route::get('/artifacts/{artifact}/content', [ArtifactController::class, 'content']);
    Route::get('/artifacts/{artifact}/download', [ArtifactController::class, 'download']);

    // Marketplace (authenticated write operations)
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

    // Webhooks
    Route::apiResource('webhooks', WebhookEndpointController::class)->parameters(['webhooks' => 'webhookEndpoint']);

    // Outbound Connectors
    Route::apiResource('outbound-connectors', OutboundConnectorConfigController::class)
        ->parameters(['outbound-connectors' => 'outboundConnectorConfig']);
    Route::post('/outbound-connectors/{outboundConnectorConfig}/test', [OutboundConnectorConfigController::class, 'test']);

    // Chatbot instances (management API, Sanctum auth)
    Route::apiResource('chatbot-instances', ChatbotInstanceController::class)
        ->parameters(['chatbot-instances' => 'chatbot']);
    Route::post('/chatbot-instances/{chatbot}/tokens', [ChatbotInstanceController::class, 'createToken']);
    Route::delete('/chatbot-instances/{chatbot}/tokens/{token}', [ChatbotInstanceController::class, 'revokeToken']);
    Route::get('/chatbot-instances/{chatbot}/conversations', [ChatbotInstanceController::class, 'conversations']);

    // Triggers
    Route::apiResource('triggers', TriggerController::class);
    Route::patch('/triggers/{trigger}/status', [TriggerController::class, 'toggleStatus']);
    Route::post('/triggers/{trigger}/test', [TriggerController::class, 'test']);

    // Memory
    Route::get('/memories', [MemoryController::class, 'index']);
    Route::get('/memories/stats', [MemoryController::class, 'stats']);
    Route::get('/memories/{memory}', [MemoryController::class, 'show']);
    Route::post('/memories', [MemoryController::class, 'store']);
    Route::delete('/memories/{memory}', [MemoryController::class, 'destroy']);
    Route::post('/memories/search', [MemoryController::class, 'search']);

    // Evolution proposals
    Route::get('/evolution', [EvolutionController::class, 'index']);
    Route::get('/evolution/{evolution}', [EvolutionController::class, 'show']);
    Route::post('/evolution/{evolution}/apply', [EvolutionController::class, 'apply']);
    Route::post('/evolution/{evolution}/reject', [EvolutionController::class, 'reject']);

    // Email templates
    Route::apiResource('email-templates', EmailTemplateController::class)
        ->parameters(['email-templates' => 'emailTemplate']);
    Route::post('/email-templates/{emailTemplate}/generate', [EmailTemplateController::class, 'generate']);

    // Email themes
    Route::apiResource('email-themes', EmailThemeController::class)
        ->parameters(['email-themes' => 'emailTheme']);

    // Integrations
    Route::get('/integrations', [IntegrationController::class, 'index']);
    Route::get('/integrations/{integration}', [IntegrationController::class, 'show']);
    Route::post('/integrations/connect', [IntegrationController::class, 'connect']);
    Route::post('/integrations/{integration}/disconnect', [IntegrationController::class, 'disconnect']);
    Route::post('/integrations/{integration}/ping', [IntegrationController::class, 'ping']);
    Route::post('/integrations/{integration}/execute', [IntegrationController::class, 'execute']);
    Route::get('/integrations/{integration}/capabilities', [IntegrationController::class, 'capabilities']);

    // Assistant conversations
    Route::get('/assistant/conversations', [AssistantController::class, 'index']);
    Route::post('/assistant/conversations', [AssistantController::class, 'store']);
    Route::get('/assistant/conversations/{conversation}', [AssistantController::class, 'show']);
    Route::delete('/assistant/conversations/{conversation}', [AssistantController::class, 'destroy']);
    Route::post('/assistant/conversations/{conversation}/messages', [AssistantController::class, 'send']);

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Audit
    Route::get('/audit', [AuditController::class, 'index']);

    // Budget
    Route::get('/budget', [BudgetController::class, 'index']);

    // Git Repositories
    Route::get('/git-repositories', [GitRepositoryController::class, 'index']);
    Route::get('/git-repositories/{gitRepository}', [GitRepositoryController::class, 'show']);
    Route::post('/git-repositories', [GitRepositoryController::class, 'store']);
    Route::put('/git-repositories/{gitRepository}', [GitRepositoryController::class, 'update']);
    Route::delete('/git-repositories/{gitRepository}', [GitRepositoryController::class, 'destroy']);
    Route::post('/git-repositories/{gitRepository}/test', [GitRepositoryController::class, 'test']);
    Route::get('/git-repositories/{gitRepository}/files', [GitRepositoryController::class, 'listFiles']);
    Route::get('/git-repositories/{gitRepository}/prs', [GitRepositoryController::class, 'listPullRequests']);

    // Provider config (LLM provider settings per team)
    Route::get('/config/providers/{provider}', [ProviderConfigController::class, 'show']);
    Route::put('/config/providers/{provider}', [ProviderConfigController::class, 'update']);

    // Bridge
    Route::get('/bridge/status', [BridgeController::class, 'status']);
    // HTTP tunnel mode (Cloudflare/Tailscale/ngrok)
    Route::post('/bridge/connect', [BridgeController::class, 'connect']);
    Route::put('/bridge/{connection}/url', [BridgeController::class, 'updateUrl']);
    Route::post('/bridge/{connection}/ping', [BridgeController::class, 'ping']);
    // Legacy relay mode (WebSocket daemon)
    Route::post('/bridge/register', [BridgeController::class, 'register']);
    Route::post('/bridge/endpoints', [BridgeController::class, 'updateEndpoints']);
    Route::post('/bridge/heartbeat', [BridgeController::class, 'heartbeat']);
    Route::post('/bridge/mcp/call', [BridgeController::class, 'mcpCall']);
    Route::delete('/bridge', [BridgeController::class, 'disconnect']);

    // Knowledge Bases (RAG)
    Route::get('/knowledge-bases', [KnowledgeBaseController::class, 'index']);
    Route::post('/knowledge-bases', [KnowledgeBaseController::class, 'store']);
    Route::get('/knowledge-bases/{knowledgeBase}', [KnowledgeBaseController::class, 'show']);
    Route::delete('/knowledge-bases/{knowledgeBase}', [KnowledgeBaseController::class, 'destroy']);
    Route::post('/knowledge-bases/{knowledgeBase}/ingest', [KnowledgeBaseController::class, 'ingest']);
    Route::post('/knowledge-bases/search', [KnowledgeBaseController::class, 'search']);

    // Knowledge Graph
    Route::get('/knowledge-graph/entities', [KnowledgeGraphController::class, 'entities']);
    Route::get('/knowledge-graph/entity-facts', [KnowledgeGraphController::class, 'entityFacts']);
    Route::post('/knowledge-graph/search', [KnowledgeGraphController::class, 'search']);
    Route::post('/knowledge-graph/facts', [KnowledgeGraphController::class, 'store']);

    // Metrics
    Route::get('/metrics', [MetricsController::class, 'index']);
    Route::get('/metrics/aggregations', [MetricsController::class, 'aggregations']);
    Route::get('/metrics/model-comparison', [MetricsController::class, 'modelComparison']);

    // Reverb WebSocket channel authentication — used by the bridge daemon to authenticate
    // its private channel subscription (POST with socket_id + channel_name, returns auth token)
    Route::post('/broadcasting/auth', [BridgeController::class, 'broadcastingAuth']);
});
