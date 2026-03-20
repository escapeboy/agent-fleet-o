<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Budget\Exceptions\InsufficientBudgetException;
use App\Domain\Crew\Models\Crew;
use App\Http\Controllers\Controller;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\Translators\OpenAiRequestTranslator;
use App\Infrastructure\AI\Translators\OpenAiResponseTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OpenAiCompatibleController extends Controller
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly OpenAiRequestTranslator $requestTranslator,
        private readonly OpenAiResponseTranslator $responseTranslator,
        private readonly ExecuteAgentAction $executeAgent,
    ) {}

    /**
     * GET /v1/models — List available models (agents + crews + raw providers).
     */
    public function listModels(Request $request): JsonResponse
    {
        $user = $request->user();
        $teamId = $user->current_team_id;

        $models = [];

        // Team's agents as models
        $agents = Agent::where('team_id', $teamId)->where('status', 'active')->get();
        foreach ($agents as $agent) {
            $models[] = [
                'id' => "agent/{$agent->slug}",
                'object' => 'model',
                'created' => $agent->created_at->timestamp,
                'owned_by' => 'fleetq',
            ];
        }

        // Team's crews as models
        $crews = Crew::where('team_id', $teamId)->where('status', 'active')->get();
        foreach ($crews as $crew) {
            $models[] = [
                'id' => "crew/{$crew->slug}",
                'object' => 'model',
                'created' => $crew->created_at->timestamp,
                'owned_by' => 'fleetq',
            ];
        }

        // Raw provider models (based on team's configured providers)
        foreach ($this->getConfiguredModels($teamId) as $modelId) {
            $models[] = [
                'id' => $modelId,
                'object' => 'model',
                'created' => 0,
                'owned_by' => explode('/', $modelId)[0] ?? 'unknown',
            ];
        }

        return response()->json([
            'object' => 'list',
            'data' => $models,
        ]);
    }

    /**
     * GET /v1/models/{model} — Retrieve a single model.
     */
    public function retrieveModel(Request $request, string $model): JsonResponse
    {
        $user = $request->user();
        $teamId = $user->current_team_id;

        $resolved = $this->requestTranslator->resolveModel($model, $teamId);

        if ($resolved['type'] === 'not_found') {
            return response()->json(
                OpenAiResponseTranslator::formatError(
                    "Model '{$model}' not found.",
                    'invalid_request_error',
                    404,
                    'model_not_found',
                ),
                404,
            );
        }

        $created = 0;
        $ownedBy = 'fleetq';

        if ($resolved['entity'] instanceof Agent) {
            $created = $resolved['entity']->created_at->timestamp;
        } elseif ($resolved['entity'] instanceof Crew) {
            $created = $resolved['entity']->created_at->timestamp;
        } else {
            $ownedBy = $resolved['provider'];
        }

        return response()->json([
            'id' => $model,
            'object' => 'model',
            'created' => $created,
            'owned_by' => $ownedBy,
        ]);
    }

    /**
     * POST /v1/chat/completions — Chat completions (streaming + non-streaming).
     */
    public function chatCompletions(Request $request): JsonResponse|StreamedResponse
    {
        $validated = $request->validate([
            'model' => 'required|string|max:255',
            'messages' => 'required|array|min:1',
            'messages.*.role' => 'required|string|in:system,user,assistant,tool',
            'messages.*.content' => 'nullable|string',
            'messages.*.tool_calls' => 'nullable|array',
            'messages.*.tool_call_id' => 'nullable|string',
            'stream' => 'nullable|boolean',
            'temperature' => 'nullable|numeric|min:0|max:2',
            'max_tokens' => 'nullable|integer|min:1|max:65536',
            'stop' => 'nullable',
            'stream_options' => 'nullable|array',
            'stream_options.include_usage' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $teamId = $user->current_team_id;
        $modelId = $validated['model'];

        // Resolve model namespace
        $resolved = $this->requestTranslator->resolveModel($modelId, $teamId);

        if ($resolved['type'] === 'not_found') {
            return response()->json(
                OpenAiResponseTranslator::formatError(
                    "Model '{$modelId}' not found.",
                    'invalid_request_error',
                    404,
                    'model_not_found',
                ),
                404,
            );
        }

        $isStream = $validated['stream'] ?? false;
        $includeUsage = $validated['stream_options']['include_usage'] ?? false;

        try {
            // Route based on type
            if ($resolved['type'] === 'agent') {
                return $this->handleAgentRequest($resolved, $validated, $user, $teamId, $modelId, $isStream, $includeUsage);
            }

            // Passthrough to AI Gateway (raw model or crew)
            return $this->handlePassthroughRequest($resolved, $validated, $user, $teamId, $modelId, $isStream, $includeUsage);
        } catch (InsufficientBudgetException $e) {
            return response()->json(
                OpenAiResponseTranslator::formatError($e->getMessage(), 'insufficient_quota', 402, 'insufficient_quota'),
                402,
            );
        } catch (\Throwable $e) {
            return response()->json(
                OpenAiResponseTranslator::formatError(
                    app()->hasDebugModeEnabled() ? $e->getMessage() : 'An internal error occurred.',
                    'server_error',
                    500,
                ),
                500,
            );
        }
    }

    private function handleAgentRequest(
        array $resolved,
        array $validated,
        $user,
        string $teamId,
        string $modelId,
        bool $isStream,
        bool $includeUsage,
    ): JsonResponse|StreamedResponse {
        /** @var Agent $agent */
        $agent = $resolved['entity'];

        // Extract user message from the last user message in the messages array
        $lastUserMessage = '';
        foreach (array_reverse($validated['messages']) as $msg) {
            if ($msg['role'] === 'user') {
                $lastUserMessage = $msg['content'] ?? '';
                break;
            }
        }

        $result = $this->executeAgent->execute(
            agent: $agent,
            input: ['task' => $lastUserMessage, 'raw_messages' => $validated['messages']],
            teamId: $teamId,
            userId: $user->id,
        );

        $content = '';
        if (isset($result['output'])) {
            $content = is_array($result['output'])
                ? ($result['output']['content'] ?? json_encode($result['output']))
                : (string) $result['output'];
        }

        $execution = $result['execution'];

        if ($isStream) {
            return $this->streamContent($modelId, $content, $includeUsage, $execution);
        }

        $id = 'chatcmpl-'.Str::ulid();

        return response()->json([
            'id' => $id,
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $modelId,
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => $content],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => $execution->input_tokens ?? 0,
                'completion_tokens' => $execution->output_tokens ?? 0,
                'total_tokens' => ($execution->input_tokens ?? 0) + ($execution->output_tokens ?? 0),
            ],
            'system_fingerprint' => 'fleetq-v1',
        ]);
    }

    private function handlePassthroughRequest(
        array $resolved,
        array $validated,
        $user,
        string $teamId,
        string $modelId,
        bool $isStream,
        bool $includeUsage,
    ): JsonResponse|StreamedResponse {
        $aiRequest = $this->requestTranslator->toAiRequest(
            validated: $validated,
            provider: $resolved['provider'],
            model: $resolved['model'],
            userId: $user->id,
            teamId: $teamId,
        );

        if ($isStream) {
            $streamId = 'chatcmpl-'.Str::ulid();
            $chunks = [];

            $response = $this->gateway->stream($aiRequest, function (string $delta) use (&$chunks) {
                $chunks[] = $delta;
            });

            return $this->buildStreamedResponse(function () use ($streamId, $modelId, $chunks, $response, $includeUsage) {
                echo $this->responseTranslator->formatStreamStart($streamId, $modelId);
                ob_flush();
                flush();

                foreach ($chunks as $chunk) {
                    echo $this->responseTranslator->formatStreamDelta($streamId, $modelId, $chunk);
                    ob_flush();
                    flush();
                }

                echo $this->responseTranslator->formatStreamEnd($streamId, $modelId);
                ob_flush();
                flush();

                if ($includeUsage) {
                    echo $this->responseTranslator->formatStreamUsage($streamId, $modelId, $response);
                    ob_flush();
                    flush();
                }

                echo $this->responseTranslator->formatStreamDone();
                ob_flush();
                flush();
            });
        }

        $response = $this->gateway->complete($aiRequest);

        return response()->json($this->responseTranslator->toOpenAiResponse($response, $modelId));
    }

    /**
     * Stream agent content character by character (simulated streaming).
     */
    private function streamContent(string $modelId, string $content, bool $includeUsage, $execution): StreamedResponse
    {
        $streamId = 'chatcmpl-'.Str::ulid();

        return $this->buildStreamedResponse(function () use ($streamId, $modelId, $content, $includeUsage, $execution) {
            echo $this->responseTranslator->formatStreamStart($streamId, $modelId);
            ob_flush();
            flush();

            // Stream in word-sized chunks for natural pacing
            $words = preg_split('/(\s+)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
            foreach ($words as $word) {
                if ($word === '') {
                    continue;
                }
                echo $this->responseTranslator->formatStreamDelta($streamId, $modelId, $word);
                ob_flush();
                flush();
            }

            echo $this->responseTranslator->formatStreamEnd($streamId, $modelId);
            ob_flush();
            flush();

            if ($includeUsage) {
                $usageChunk = [
                    'id' => $streamId,
                    'object' => 'chat.completion.chunk',
                    'created' => time(),
                    'model' => $modelId,
                    'choices' => [],
                    'usage' => [
                        'prompt_tokens' => $execution->input_tokens ?? 0,
                        'completion_tokens' => $execution->output_tokens ?? 0,
                        'total_tokens' => ($execution->input_tokens ?? 0) + ($execution->output_tokens ?? 0),
                    ],
                ];
                echo 'data: '.json_encode($usageChunk)."\n\n";
                ob_flush();
                flush();
            }

            echo $this->responseTranslator->formatStreamDone();
            ob_flush();
            flush();
        });
    }

    private function buildStreamedResponse(callable $callback): StreamedResponse
    {
        return new StreamedResponse($callback, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Get raw provider models available to the team.
     */
    private function getConfiguredModels(string $teamId): array
    {
        $models = [];

        $pricing = config('llm_pricing.providers', []);
        foreach ($pricing as $provider => $providerModels) {
            // Skip local/free providers
            if (in_array($provider, ['codex', 'claude-code', 'kiro', 'gemini-cli'], true)) {
                continue;
            }

            foreach (array_keys($providerModels) as $model) {
                $models[] = "{$provider}/{$model}";
            }
        }

        return $models;
    }
}
