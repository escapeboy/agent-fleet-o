<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Chatbot\Actions\CreateChatbotAction;
use App\Domain\Chatbot\Actions\CreateChatbotTokenAction;
use App\Domain\Chatbot\Actions\DeleteChatbotAction;
use App\Domain\Chatbot\Actions\RevokeChatbotTokenAction;
use App\Domain\Chatbot\Actions\UpdateChatbotAction;
use App\Domain\Chatbot\Enums\ChatbotType;
use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotToken;
use App\Domain\Shared\Models\Team;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ChatbotResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Chatbots
 */
class ChatbotInstanceController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            function (Request $request, \Closure $next): mixed {
                /** @var Team|null $team */
                $team = $request->user()?->currentTeam;
                abort_unless(
                    $team instanceof Team && ($team->settings['chatbot_enabled'] ?? false),
                    403,
                    'Chatbot feature is not enabled for this team.',
                );

                return $next($request);
            },
        ];
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $chatbots = QueryBuilder::for(Chatbot::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('type'),
                AllowedFilter::partial('name'),
            ])
            ->allowedSorts(['created_at', 'updated_at', 'name'])
            ->defaultSort('-created_at')
            ->cursorPaginate(min((int) $request->input('per_page', 15), 100));

        return ChatbotResource::collection($chatbots);
    }

    public function show(Chatbot $chatbot): ChatbotResource
    {
        return new ChatbotResource($chatbot->load('agent'));
    }

    public function store(Request $request, CreateChatbotAction $action): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|min:2|max:255',
            'type' => 'required|string|in:'.implode(',', array_column(ChatbotType::cases(), 'value')),
            'agent_id' => 'nullable|uuid|exists:agents,id',
            'provider' => 'nullable|string',
            'model' => 'nullable|string',
            'system_prompt' => 'nullable|string',
            'welcome_message' => 'nullable|string|max:500',
            'fallback_message' => 'nullable|string|max:500',
        ]);

        $result = $action->execute(
            name: $data['name'],
            type: ChatbotType::from($data['type']),
            teamId: $request->user()->current_team_id,
            agentId: $data['agent_id'] ?? null,
            provider: $data['provider'] ?? null,
            model: $data['model'] ?? null,
            systemPrompt: $data['system_prompt'] ?? null,
            welcomeMessage: $data['welcome_message'] ?? null,
            fallbackMessage: $data['fallback_message'] ?? null,
        );

        return response()->json([
            'chatbot' => new ChatbotResource($result['chatbot']),
            'plaintext_token' => $result['plaintext_token'],
        ], 201);
    }

    public function update(Request $request, Chatbot $chatbot, UpdateChatbotAction $action): ChatbotResource
    {
        $data = $request->validate([
            'name' => 'sometimes|string|min:2|max:255',
            'description' => 'nullable|string',
            'welcome_message' => 'nullable|string|max:500',
            'fallback_message' => 'nullable|string|max:500',
            'confidence_threshold' => 'nullable|numeric|min:0.1|max:1.0',
            'human_escalation_enabled' => 'nullable|boolean',
            'config' => 'nullable|array',
            'widget_config' => 'nullable|array',
            'provider' => 'sometimes|nullable|string|max:50',
            'model' => 'sometimes|nullable|string|max:100',
            'system_prompt' => 'sometimes|nullable|string|max:5000',
        ]);

        $action->execute(
            chatbot: $chatbot,
            name: $data['name'] ?? null,
            description: $data['description'] ?? null,
            welcomeMessage: $data['welcome_message'] ?? null,
            fallbackMessage: $data['fallback_message'] ?? null,
            confidenceThreshold: isset($data['confidence_threshold']) ? (float) $data['confidence_threshold'] : null,
            humanEscalationEnabled: $data['human_escalation_enabled'] ?? null,
            config: $data['config'] ?? null,
            widgetConfig: $data['widget_config'] ?? null,
            provider: $data['provider'] ?? null,
            model: $data['model'] ?? null,
            systemPrompt: $data['system_prompt'] ?? null,
        );

        return new ChatbotResource($chatbot->fresh());
    }

    public function destroy(Chatbot $chatbot, DeleteChatbotAction $action): JsonResponse
    {
        $action->execute($chatbot);

        return response()->json(['message' => 'Chatbot deleted.']);
    }

    public function createToken(Request $request, Chatbot $chatbot, CreateChatbotTokenAction $action): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'allowed_origins' => 'nullable|array',
            'allowed_origins.*' => 'string|max:255',
        ]);

        $result = $action->execute(
            chatbot: $chatbot,
            name: $data['name'],
            allowedOrigins: $data['allowed_origins'] ?? [],
        );

        return response()->json([
            'token' => $result['token'],
            'prefix' => $result['prefix'],
            'name' => $data['name'],
        ], 201);
    }

    public function revokeToken(Chatbot $chatbot, string $token, RevokeChatbotTokenAction $action): JsonResponse
    {
        $tokenModel = ChatbotToken::where('id', $token)
            ->where('chatbot_id', $chatbot->id)
            ->firstOrFail();

        $action->execute($tokenModel);

        return response()->json(['message' => 'Token revoked.']);
    }

    public function conversations(Request $request, Chatbot $chatbot): JsonResponse
    {
        $sessions = $chatbot->sessions()
            ->withCount('messages')
            ->orderByDesc('created_at')
            ->cursorPaginate(20);

        return response()->json($sessions);
    }
}
