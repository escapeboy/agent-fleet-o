<?php

namespace App\Http\Controllers\Api\Chatbot;

use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotSession;
use App\Domain\Chatbot\Services\ChatbotResponseService;
use App\Domain\Shared\Models\Team;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function __construct(
        private readonly ChatbotResponseService $responseService,
    ) {}

    /**
     * Create or resume a session.
     * POST /chatbot/sessions
     */
    public function createSession(Request $request): JsonResponse
    {
        /** @var Chatbot $chatbot */
        $chatbot = $request->attributes->get('chatbot');

        if (! $chatbot->isActive()) {
            return response()->json(['error' => 'Chatbot is not active.'], 503);
        }

        $sessionId = $request->input('session_id');

        if ($sessionId) {
            $session = ChatbotSession::where('id', $sessionId)
                ->where('chatbot_id', $chatbot->id)
                ->first();

            if ($session) {
                return response()->json([
                    'session_id' => $session->id,
                    'resumed' => true,
                    'welcome_message' => null,
                ]);
            }
        }

        $session = ChatbotSession::create([
            'chatbot_id' => $chatbot->id,
            'team_id' => $chatbot->team_id,
            'channel' => 'web_widget',
            'ip_address' => $request->ip(),
            'metadata' => ['user_agent' => Str::limit($request->userAgent() ?? '', 200)],
            'started_at' => now(),
        ]);

        return response()->json([
            'session_id' => $session->id,
            'resumed' => false,
            'welcome_message' => $chatbot->welcome_message,
        ], 201);
    }

    /**
     * Send a message and receive a response.
     * POST /chatbot/sessions/{session}/messages
     */
    public function sendMessage(Request $request, string $sessionId): JsonResponse
    {
        /** @var Chatbot $chatbot */
        $chatbot = $request->attributes->get('chatbot');

        if (! $chatbot->isActive()) {
            return response()->json(['error' => 'Chatbot is not active.'], 503);
        }

        if (! $chatbot->hasBudgetRemaining()) {
            return response()->json(['error' => 'Budget exhausted. Please contact support.'], 429);
        }

        $session = ChatbotSession::where('id', $sessionId)
            ->where('chatbot_id', $chatbot->id)
            ->first();

        if (! $session) {
            return response()->json(['error' => 'Session not found.'], 404);
        }

        $request->validate(['message' => 'required|string|max:4000']);

        $result = $this->responseService->handle(
            chatbot: $chatbot,
            session: $session,
            userText: $request->input('message'),
            actorUserId: $chatbot->agent?->user_id
                ?? Team::where('id', $chatbot->team_id)->value('owner_id')
                ?? $chatbot->team_id,
        );

        if ($result['escalated']) {
            return response()->json([
                'id' => $result['message']->id,
                'role' => 'assistant',
                'content' => null,
                'escalated' => true,
                'escalation_message' => 'Your message has been sent for review. You will be notified when a response is ready.',
                'confidence' => $result['message']->confidence,
            ]);
        }

        return response()->json([
            'id' => $result['message']->id,
            'role' => 'assistant',
            'content' => $result['reply'],
            'escalated' => false,
            'confidence' => $result['message']->confidence,
        ]);
    }

    /**
     * Send a message and stream the response via SSE.
     * POST /chatbot/sessions/{session}/messages/stream
     */
    public function sendMessageStream(Request $request, string $sessionId): StreamedResponse
    {
        /** @var Chatbot $chatbot */
        $chatbot = $request->attributes->get('chatbot');

        if (! $chatbot->isActive()) {
            abort(503, 'Chatbot is not active.');
        }

        if (! $chatbot->hasBudgetRemaining()) {
            abort(429, 'Budget exhausted.');
        }

        $session = ChatbotSession::where('id', $sessionId)
            ->where('chatbot_id', $chatbot->id)
            ->first();

        if (! $session) {
            abort(404, 'Session not found.');
        }

        $request->validate(['message' => 'required|string|max:4000']);

        $userText = $request->input('message');
        $actorUserId = $chatbot->agent?->user_id
            ?? Team::where('id', $chatbot->team_id)->value('owner_id')
            ?? $chatbot->team_id;

        $headers = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ];

        return response()->stream(function () use ($chatbot, $session, $userText, $actorUserId) {
            $sendEvent = function (string $type, array $data = []) {
                echo 'data: '.json_encode(['type' => $type, ...$data])."\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            };

            $sendEvent('start', ['session_id' => $session->id]);

            try {
                $message = $this->responseService->handleStream(
                    chatbot: $chatbot,
                    session: $session,
                    userText: $userText,
                    actorUserId: $actorUserId,
                    onChunk: function (string $chunk) {
                        echo 'data: '.json_encode(['type' => 'chunk', 'text' => $chunk])."\n\n";
                        if (ob_get_level()) {
                            ob_flush();
                        }
                        flush();
                    },
                );

                $sendEvent('done', [
                    'id' => $message->id,
                    'confidence' => $message->confidence,
                ]);
            } catch (\Throwable $e) {
                $sendEvent('error', ['message' => 'An error occurred. Please try again.']);
            }
        }, 200, $headers);
    }

    /**
     * SSE endpoint for receiving approved responses in real-time.
     * GET /chatbot/sessions/{session}/events
     */
    public function events(Request $request, string $sessionId): StreamedResponse
    {
        /** @var Chatbot $chatbot */
        $chatbot = $request->attributes->get('chatbot');

        $session = ChatbotSession::where('id', $sessionId)
            ->where('chatbot_id', $chatbot->id)
            ->first();

        if (! $session) {
            abort(404, 'Session not found.');
        }

        $headers = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ];

        return response()->stream(function () use ($session) {
            echo 'data: '.json_encode(['type' => 'connected', 'session_id' => $session->id])."\n\n";
            ob_flush();
            flush();

            // Keep-alive ping every 30 seconds
            // Actual event delivery is handled by broadcasting via Reverb.
            // This endpoint exists as a fallback for environments without WebSocket support.
            $timeout = 60;
            $elapsed = 0;

            while ($elapsed < $timeout && ! connection_aborted()) {
                sleep(15);
                $elapsed += 15;
                echo ": keepalive\n\n";
                ob_flush();
                flush();
            }
        }, 200, $headers);
    }
}
