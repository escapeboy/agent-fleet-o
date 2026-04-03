<?php

namespace App\Domain\Shared\Services;

use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Signal\Models\Signal;
use Illuminate\Support\Carbon;

class ContactHealthScorer
{
    private const RECENCY_WEIGHT = 0.40;

    private const FREQUENCY_WEIGHT = 0.35;

    private const SENTIMENT_WEIGHT = 0.25;

    private const POSITIVE_KEYWORDS = ['thank', 'great', 'love', 'excellent', 'good', 'happy', 'please', 'appreciate'];

    private const NEGATIVE_KEYWORDS = ['cancel', 'complaint', 'angry', 'issue', 'problem', 'refund', 'unsubscribe', 'stop', 'disappointed'];

    /**
     * Score a single ContactIdentity.
     *
     * Returns an array with health_score (0.0–1.0) and its component scores.
     *
     * @return array{health_score: float, health_recency_score: float, health_frequency_score: float, health_sentiment_score: float, health_scored_at: Carbon}
     */
    public function score(ContactIdentity $contact): array
    {
        $recency = $this->recencyScore($contact);
        $frequency = $this->frequencyScore($contact);
        $sentiment = $this->sentimentScore($contact);

        $composite = round(
            ($recency * self::RECENCY_WEIGHT)
            + ($frequency * self::FREQUENCY_WEIGHT)
            + ($sentiment * self::SENTIMENT_WEIGHT),
            4,
        );

        return [
            'health_score' => $composite,
            'health_recency_score' => $recency,
            'health_frequency_score' => $frequency,
            'health_sentiment_score' => $sentiment,
            'health_scored_at' => now(),
        ];
    }

    /**
     * Exponential decay based on days since the most recent signal.
     * decay = exp(-days / 30): 1.0 today, ~0.37 at 30 days, ~0.05 at 90 days.
     */
    private function recencyScore(ContactIdentity $contact): float
    {
        $latest = Signal::withoutGlobalScopes()
            ->where('contact_identity_id', $contact->id)
            ->max('received_at');

        if (! $latest) {
            return 0.0;
        }

        $days = Carbon::parse($latest)->diffInDays(now(), absolute: true);

        return round((float) exp(-$days / 30), 4);
    }

    /**
     * Normalize 30-day signal count against team average.
     * min(count / avg, 1.0). If avg is 0, uses count directly (capped at 1.0).
     */
    private function frequencyScore(ContactIdentity $contact): float
    {
        $since = now()->subDays(30);

        $contactCount = (int) Signal::withoutGlobalScopes()
            ->where('contact_identity_id', $contact->id)
            ->where('received_at', '>=', $since)
            ->count();

        // Average signals per contact for this team over the past 30 days
        $teamContacts = ContactIdentity::withoutGlobalScopes()
            ->where('team_id', $contact->team_id)
            ->count();

        if ($teamContacts === 0) {
            return min($contactCount, 1.0);
        }

        $teamTotal = (int) Signal::withoutGlobalScopes()
            ->whereHas('contactIdentity', fn ($q) => $q->where('team_id', $contact->team_id))
            ->where('received_at', '>=', $since)
            ->count();

        $avg = $teamTotal / $teamContacts;

        if ($avg <= 0) {
            return min($contactCount, 1.0);
        }

        return (float) min($contactCount / $avg, 1.0);
    }

    /**
     * Keyword-based sentiment from last 5 signal payloads.
     * score = (positive - negative) / total + 0.5, clamped to [0.1, 0.9].
     * Returns 0.5 (neutral) if no signals.
     */
    private function sentimentScore(ContactIdentity $contact): float
    {
        $signals = Signal::withoutGlobalScopes()
            ->where('contact_identity_id', $contact->id)
            ->orderByDesc('received_at')
            ->limit(5)
            ->pluck('payload');

        if ($signals->isEmpty()) {
            return 0.5;
        }

        $positive = 0;
        $negative = 0;

        foreach ($signals as $payload) {
            $text = strtolower($this->extractText($payload));

            foreach (self::POSITIVE_KEYWORDS as $word) {
                if (str_contains($text, $word)) {
                    $positive++;
                }
            }

            foreach (self::NEGATIVE_KEYWORDS as $word) {
                if (str_contains($text, $word)) {
                    $negative++;
                }
            }
        }

        $total = $signals->count();
        $raw = ($positive - $negative) / $total + 0.5;

        return (float) round(max(0.1, min(0.9, $raw)), 4);
    }

    /**
     * Extract text content from a signal payload array.
     */
    private function extractText(?array $payload): string
    {
        if (! $payload) {
            return '';
        }

        $parts = [];

        foreach (['body', 'content', 'text', 'message', 'description', 'subject'] as $key) {
            if (! empty($payload[$key]) && is_string($payload[$key])) {
                $parts[] = $payload[$key];
            }
        }

        return implode(' ', $parts);
    }
}
