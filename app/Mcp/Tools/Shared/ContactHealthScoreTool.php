<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Shared\Models\ContactIdentity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ContactHealthScoreTool extends Tool
{
    protected string $name = 'contact_health_score';

    protected string $description = 'Get the relationship health score for a contact identity. Returns overall score (0.0–1.0) and sub-scores for recency, frequency, and sentiment.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'contact_id' => $schema->string()
                ->description('UUID of the ContactIdentity')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['contact_id' => 'required|uuid']);

        $teamId = app('mcp.team_id');

        $contact = ContactIdentity::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('id', $request->get('contact_id'))
            ->first(['id', 'display_name', 'email', 'health_score', 'health_recency_score', 'health_frequency_score', 'health_sentiment_score', 'health_scored_at']);

        if (! $contact) {
            return Response::text(json_encode(['error' => 'Contact not found']));
        }

        return Response::text(json_encode([
            'id' => $contact->id,
            'display_name' => $contact->display_name,
            'email' => $contact->email,
            'health_score' => $contact->health_score,
            'health_recency_score' => $contact->health_recency_score,
            'health_frequency_score' => $contact->health_frequency_score,
            'health_sentiment_score' => $contact->health_sentiment_score,
            'health_scored_at' => $contact->health_scored_at?->toIso8601String(),
            'health_label' => $this->healthLabel($contact->health_score),
        ]));
    }

    private function healthLabel(?float $score): string
    {
        if ($score === null) {
            return 'unscored';
        }

        return match (true) {
            $score >= 0.7 => 'healthy',
            $score >= 0.4 => 'at_risk',
            default => 'cold',
        };
    }
}
