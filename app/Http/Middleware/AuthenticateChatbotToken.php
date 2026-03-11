<?php

namespace App\Http\Middleware;

use App\Domain\Chatbot\Models\ChatbotToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateChatbotToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (! $bearerToken) {
            return response()->json(['error' => 'unauthenticated', 'message' => 'No token provided.'], 401);
        }

        $tokenHash = hash('sha256', $bearerToken);

        $token = ChatbotToken::with('chatbot')
            ->where('token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (! $token) {
            return response()->json(['error' => 'unauthenticated', 'message' => 'Invalid or expired token.'], 401);
        }

        $chatbot = $token->chatbot;

        if (! $chatbot || $chatbot->trashed()) {
            return response()->json(['error' => 'not_found', 'message' => 'Chatbot not found.'], 404);
        }

        // Validate allowed origins if configured
        $allowedOrigins = $token->allowed_origins ?? [];
        if (! empty($allowedOrigins)) {
            $origin = $request->header('Origin') ?? $request->header('Referer');
            if ($origin) {
                $host = parse_url($origin, PHP_URL_HOST);
                if (! in_array($host, $allowedOrigins)) {
                    return response()->json(['error' => 'forbidden', 'message' => 'Origin not allowed.'], 403);
                }
            }
        }

        // Update last_used_at (non-blocking)
        $token->updateQuietly(['last_used_at' => now()]);

        // Bind chatbot and token to the request for use in controllers
        $request->attributes->set('chatbot', $chatbot);
        $request->attributes->set('chatbot_token', $token);

        return $next($request);
    }
}
