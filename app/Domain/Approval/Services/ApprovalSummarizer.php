<?php

declare(strict_types=1);

namespace App\Domain\Approval\Services;

use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Support\LlmDefaults;
use Illuminate\Support\Facades\Log;

/**
 * Produces a one-glance LLM risk summary for an approval card.
 *
 * Borrowed from clawpatrol's `llm_approver` (a classifier that enriches the
 * human approval card with context before they decide). Best-effort: callers
 * wrap this so a gateway failure never blocks approval availability.
 */
class ApprovalSummarizer
{
    public function __construct(private readonly AiGatewayInterface $gateway) {}

    /**
     * @return array{summary: string, risk: string, rationale: string}
     *
     * @throws \RuntimeException on malformed or out-of-enum LLM output
     */
    public function summarize(ApprovalRequest $approvalRequest): array
    {
        $request = new AiRequestDTO(
            provider: LlmDefaults::provider(),
            model: LlmDefaults::model(),
            systemPrompt: $this->systemPrompt(),
            userPrompt: $this->buildUserPrompt($approvalRequest),
            maxTokens: 300,
            // userId is mandatory: a null userId makes the gateway gate deny
            // even platform requests. Fall back to the team owner.
            userId: Team::ownerIdFor($approvalRequest->team_id),
            teamId: $approvalRequest->team_id,
            purpose: 'approval_summary',
            temperature: 0.0,
        );

        $response = $this->gateway->complete($request);

        return $this->parseStructuredJson($response->content, $approvalRequest->id);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You summarize a pending human-approval request for an operator who must decide approve/reject.

Return STRICTLY JSON in this shape, no markdown, no prose outside the object:

{
  "summary": "One sentence: what is being approved and its effect.",
  "risk": "low" | "medium" | "high",
  "rationale": "1-2 sentences on why this risk level (blast radius, reversibility)."
}

Risk guidance:
- high = irreversible, production data/credentials, broad blast radius, outbound to many recipients.
- medium = reversible but consequential, single external action, config change.
- low = routine, easily reversible, internal-only.
PROMPT;
    }

    private function buildUserPrompt(ApprovalRequest $approvalRequest): string
    {
        $context = $approvalRequest->context ?? [];
        $title = (string) ($context['experiment_title'] ?? '');
        $thesis = (string) ($context['experiment_thesis'] ?? '');
        $type = (string) ($context['type'] ?? 'experiment_outbound');

        $lines = [
            "Approval type: {$type}",
            "Title: {$title}",
            "Thesis: {$thesis}",
        ];

        if ($approvalRequest->isCredentialReview()) {
            $lines[] = 'Subject: external service credential activation.';
        }
        if ($approvalRequest->outbound_proposal_id !== null) {
            $lines[] = 'Subject: outbound delivery to external recipients.';
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{summary: string, risk: string, rationale: string}
     */
    private function parseStructuredJson(string $raw, string $approvalId): array
    {
        $trimmed = trim($raw);
        $trimmed = (string) preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $trimmed);

        // Tolerate leading/trailing prose by scanning to the outermost braces.
        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $trimmed = substr($trimmed, $start, $end - $start + 1);
        }

        $decoded = json_decode(trim($trimmed), true);

        if (! is_array($decoded) || ! isset($decoded['summary'])) {
            Log::warning('ApprovalSummarizer: malformed LLM output', [
                'approval_id' => $approvalId,
                'raw' => mb_substr($raw, 0, 500),
            ]);
            throw new \RuntimeException('Summarizer returned malformed JSON');
        }

        $risk = is_string($decoded['risk'] ?? null) ? strtolower($decoded['risk']) : 'medium';
        if (! in_array($risk, ['low', 'medium', 'high'], true)) {
            $risk = 'medium';
        }

        return [
            'summary' => (string) $decoded['summary'],
            'risk' => $risk,
            'rationale' => (string) ($decoded['rationale'] ?? ''),
        ];
    }
}
