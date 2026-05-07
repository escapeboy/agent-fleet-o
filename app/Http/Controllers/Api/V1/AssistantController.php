<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Assistant\Actions\AnnotateMessageAction;
use App\Domain\Assistant\Actions\ReviewAssistantConversationAction;
use App\Domain\Assistant\Actions\SendAssistantMessageAction;
use App\Domain\Assistant\Enums\AnnotationRating;
use App\Domain\Assistant\Jobs\ProcessAssistantMessageJob;
use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Models\AssistantMessage;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AssistantConversationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Assistant
 */
class AssistantController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $conversations = QueryBuilder::for(AssistantConversation::class)
            ->where('user_id', $request->user()->id)
            ->allowedSorts('created_at', 'last_message_at')
            ->defaultSort('-last_message_at')
            ->cursorPaginate(min((int) $request->input('per_page', 20), 100));

        return AssistantConversationResource::collection($conversations);
    }

    public function show(AssistantConversation $conversation): JsonResponse
    {
        $messages = $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn (AssistantMessage $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'token_usage' => $m->token_usage,
                'created_at' => $m->created_at,
            ]);

        return response()->json([
            'conversation' => new AssistantConversationResource($conversation),
            'messages' => $messages,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'context_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'context_id' => ['sometimes', 'nullable', 'string'],
        ]);

        $conversation = AssistantConversation::create([
            'team_id' => $request->user()->current_team_id,
            'user_id' => $request->user()->id,
            'title' => $request->input('title', 'API Conversation'),
            'context_type' => $request->input('context_type'),
            'context_id' => $request->input('context_id'),
        ]);

        return (new AssistantConversationResource($conversation))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(AssistantConversation $conversation): JsonResponse
    {
        $conversation->messages()->delete();
        $conversation->delete();

        return response()->json(['message' => 'Conversation deleted.']);
    }

    /**
     * Annotate an assistant message with a thumbs-up/down rating and optional correction.
     */
    public function annotate(Request $request, AssistantMessage $message, AnnotateMessageAction $action): JsonResponse
    {
        // Verify the message belongs to the authenticated user's team (IDOR guard).
        // AssistantMessage has no TeamScope so we check via its conversation.
        $ownerTeamId = $message->conversation()->withoutGlobalScopes()->value('team_id');
        abort_if($ownerTeamId !== $request->user()->current_team_id, 403);

        $validated = $request->validate([
            'rating' => ['required', 'string', 'in:positive,negative'],
            'correction' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $annotation = $action->execute(
            message: $message,
            userId: $request->user()->id,
            rating: AnnotationRating::from($validated['rating']),
            correction: $validated['correction'] ?? null,
            note: $validated['note'] ?? null,
        );

        return response()->json([
            'id' => $annotation->id,
            'message_id' => $annotation->message_id,
            'rating' => $annotation->rating->value,
            'correction' => $annotation->correction,
            'note' => $annotation->note,
            'created_at' => $annotation->created_at,
        ]);
    }

    /**
     * Review the quality of an assistant conversation.
     */
    public function reviewConversation(Request $request, AssistantConversation $conversation, ReviewAssistantConversationAction $action): JsonResponse
    {
        // Authorization: only team members can review their own team's conversations
        abort_if($conversation->team_id !== $request->user()->current_team_id, 403);

        $review = $action->execute($conversation);

        return response()->json(['review' => $review]);
    }

    /**
     * Send a message and get the assistant's reply.
     *
     * Sync mode (default): runs the full tool loop in-process and returns
     * the final assistant message. Capped by the web server request timeout
     * — behind Cloudflare that's ~100s.
     *
     * Async mode (`async: true` in body): enqueues the work on the
     * `ai-calls` queue (timeout 900s) and returns 202 immediately with a
     * placeholder message id. The client polls
     * GET /api/v1/assistant/conversations/{id} and reads the placeholder's
     * `metadata.status` (queued|streaming|continuing|completed|failed) +
     * content. Use this for autonomous tasks that take more than ~90s.
     */
    public function send(Request $request, AssistantConversation $conversation, SendAssistantMessageAction $action): JsonResponse
    {
        $request->validate([
            'message' => ['required', 'string', 'min:1'],
            'context_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'context_id' => ['sometimes', 'nullable', 'string'],
            'async' => ['sometimes', 'boolean'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:64'],
            'model' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);

        $contextType = $request->input('context_type', $conversation->context_type);
        $contextId = $request->input('context_id', $conversation->context_id);
        $async = filter_var(
            $request->input('async', $request->query('async', false)),
            FILTER_VALIDATE_BOOLEAN,
        );

        if ($async) {
            $userMessage = $request->input('message');

            // Persist the user message so the conversation reflects it
            // immediately — the polling client sees it on the next read.
            $conversation->messages()->create([
                'role' => 'user',
                'content' => $userMessage,
                'created_at' => now(),
            ]);

            // Placeholder assistant message the job will update in place.
            $placeholder = $conversation->messages()->create([
                'role' => 'assistant',
                'content' => '',
                'metadata' => ['status' => 'queued', 'async' => true],
                'created_at' => now(),
            ]);

            $conversation->update(['last_message_at' => now()]);

            ProcessAssistantMessageJob::dispatch(
                conversationId: $conversation->id,
                placeholderMessageId: $placeholder->id,
                userMessage: $userMessage,
                userId: $request->user()->id,
                teamId: $request->user()->current_team_id,
                provider: $request->input('provider'),
                model: $request->input('model'),
                contextType: $contextType,
                contextId: $contextId,
            );

            return response()->json([
                'async' => true,
                'queued' => true,
                'conversation_id' => $conversation->id,
                'message_id' => $placeholder->id,
                'poll_url' => "/api/v1/assistant/conversations/{$conversation->id}",
                'status' => 'queued',
            ], 202);
        }

        $response = $action->execute(
            conversation: $conversation,
            userMessage: $request->input('message'),
            user: $request->user(),
            contextType: $contextType,
            contextId: $contextId,
        );

        // Fetch the assistant reply that was just saved
        $reply = $conversation->messages()
            ->where('role', 'assistant')
            ->latest('created_at')
            ->first();

        return response()->json([
            'reply' => $reply ? [
                'id' => $reply->id,
                'role' => $reply->role,
                'content' => $reply->content,
                'token_usage' => $reply->token_usage,
                'created_at' => $reply->created_at,
            ] : null,
            'total_tokens' => $response->usage->totalTokens(),
        ]);
    }
}
