<?php

namespace App\Domain\Assistant\Services;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Enums\CrewStatus;
use App\Domain\Crew\Models\Crew;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;

/**
 * Routes a free-text request to the best-fit handler on the team — the "front
 * door" router (eve "V" borrow). Ranks the team's active agents, crews, and
 * projects by deterministic token overlap against each candidate's identity
 * text, so one ask can be pointed at whoever should handle it instead of the
 * user having to know the whole fleet.
 *
 * Deterministic and team-scoped; the assistant uses the ranking to recommend
 * or dispatch.
 */
class RequestRouter
{
    /** @var list<string> */
    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'that', 'this', 'you', 'your', 'our',
        'can', 'how', 'what', 'who', 'please', 'need', 'want', 'help', 'are',
        'about', 'into', 'from', 'have', 'has', 'will', 'should',
    ];

    /**
     * @return list<array{kind: string, id: string, name: string, score: int, why: list<string>}>
     */
    public function route(string $teamId, string $request, int $limit = 5): array
    {
        $tokens = $this->tokenize($request);
        if ($tokens === []) {
            return [];
        }

        $ranked = [];

        foreach (Agent::where('team_id', $teamId)->where('status', AgentStatus::Active)->get() as $agent) {
            $why = $this->matched($tokens, $this->haystack([
                $agent->name,
                $agent->role,
                $agent->goal,
                $agent->backstory,
                is_array($agent->capabilities) ? implode(' ', $agent->capabilities) : null,
            ]));
            if ($why !== []) {
                $ranked[] = ['kind' => 'agent', 'id' => (string) $agent->id, 'name' => (string) $agent->name, 'score' => count($why), 'why' => $why];
            }
        }

        foreach (Crew::where('team_id', $teamId)->where('status', CrewStatus::Active)->get() as $crew) {
            $why = $this->matched($tokens, $this->haystack([$crew->name, $crew->description]));
            if ($why !== []) {
                $ranked[] = ['kind' => 'crew', 'id' => (string) $crew->id, 'name' => (string) $crew->name, 'score' => count($why), 'why' => $why];
            }
        }

        foreach (Project::where('team_id', $teamId)->where('status', ProjectStatus::Active)->get() as $project) {
            $why = $this->matched($tokens, $this->haystack([$project->title, $project->description, $project->goal]));
            if ($why !== []) {
                $ranked[] = ['kind' => 'project', 'id' => (string) $project->id, 'name' => (string) $project->title, 'score' => count($why), 'why' => $why];
            }
        }

        usort($ranked, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($ranked, 0, max(1, $limit));
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        preg_match_all('/[a-z0-9]+/i', mb_strtolower($text), $matches);

        $tokens = array_filter(
            $matches[0],
            fn ($t) => mb_strlen($t) >= 3 && ! in_array($t, self::STOPWORDS, true),
        );

        return array_values(array_unique($tokens));
    }

    /**
     * @param  list<?string>  $parts
     */
    private function haystack(array $parts): string
    {
        return mb_strtolower(implode(' ', array_filter($parts, fn ($p) => is_string($p) && $p !== '')));
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function matched(array $tokens, string $haystack): array
    {
        if ($haystack === '') {
            return [];
        }

        return array_values(array_filter($tokens, fn ($t) => str_contains($haystack, $t)));
    }
}
