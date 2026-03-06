<?php

namespace App\Domain\Assistant\Services;

use Prism\Prism\Tool as PrismToolObject;

/**
 * Lightweight intent classifier that determines whether a user message
 * requires a platform tool call or is purely conversational/informational.
 *
 * Uses local pattern matching (no extra API call) to avoid consuming rate
 * limit quota, adding latency, or failing when the provider is rate limited.
 *
 * Detects action-oriented messages (create, update, delete, list, show…)
 * in English and Bulgarian and returns true to force toolChoice=Any on
 * providers (e.g. Gemini) that need explicit forcing to call tools.
 */
class AssistantIntentClassifier
{
    /**
     * @param  array<PrismToolObject>  $tools
     */
    public function requiresToolCall(
        string $message,
        array $tools,
        string $provider,
        string $model,
        string $userId,
        ?string $teamId,
    ): bool {
        if (empty($tools)) {
            return false;
        }

        $lower = mb_strtolower(trim($message));

        // Pure question patterns → conversational, no tool forcing needed.
        $questionStarters = [
            'what ', "what's ", 'how ', 'why ', 'when ', 'where ', 'who ',
            'explain ', 'describe ', 'tell me about', 'can you explain',
            'is it ', 'are there ', 'does ', 'do you ',
            // Bulgarian
            'какво ', 'какво е ', 'как ', 'защо ', 'кога ', 'кой ', 'коя ', 'кои ',
            'обясни ', 'разкажи ', 'можеш ли да обясниш',
        ];

        foreach ($questionStarters as $starter) {
            if (str_starts_with($lower, $starter)) {
                return false;
            }
        }

        if (str_ends_with($lower, '?') && mb_strlen($lower) < 80) {
            return false;
        }

        // Action keywords → force tool calling.
        $actionKeywords = [
            // English — creation / mutation
            'create ', 'create a ', 'create an ', 'make a ', 'make an ', 'make me ',
            'generate ', 'build a ', 'build an ', 'add a ', 'add an ',
            'update ', 'edit ', 'modify ', 'change ', 'rename ',
            'delete ', 'remove ', 'archive ', 'kill ', 'disable ', 'enable ',
            'pause ', 'resume ', 'activate ', 'execute ', 'run ', 'trigger ',
            'schedule ', 'upload ', 'approve ', 'reject ',
            // English — retrieval / display
            'list ', 'show me', 'show my', 'get me', 'find me', 'fetch ',
            'display ', 'give me the', 'what are my', 'what is my',
            // Bulgarian — creation / mutation
            'създай', 'направи', 'генерирай', 'добави', 'нов ', 'нова ', 'ново ',
            'обнови', 'промени', 'изтрий', 'архивирай', 'спри ', 'пусни',
            'активирай', 'изпълни', 'стартирай', 'качи ', 'одобри', 'откажи',
            // Bulgarian — retrieval / display
            'покажи', 'намери', 'провери', 'изведи', 'вземи',
        ];

        foreach ($actionKeywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }

        // Default: don't force a tool call for unrecognised messages.
        // The CRITICAL system prompt instruction + toolChoice=auto handles the rest.
        return false;
    }
}
