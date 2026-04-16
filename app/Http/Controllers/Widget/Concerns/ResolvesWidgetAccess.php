<?php

namespace App\Http\Controllers\Widget\Concerns;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Signal;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

trait ResolvesWidgetAccess
{
    protected function resolveTeam(string $publicKey): Team
    {
        $team = Team::query()->where('widget_public_key', $publicKey)->first();

        if (! $team) {
            throw new HttpResponseException(response()->json(['error' => 'invalid_key'], 401));
        }

        return $team;
    }

    protected function resolveBugReportSignal(Team $team, string $signalId): Signal
    {
        $signal = Signal::query()
            ->where('team_id', $team->id)
            ->where('source_type', 'bug_report')
            ->find($signalId);

        if (! $signal) {
            throw new HttpResponseException(response()->json(['error' => 'not_found'], 404));
        }

        return $signal;
    }

    protected function throttle(string $key, int $maxAttempts, int $decaySeconds = 60): void
    {
        if (! RateLimiter::attempt($key, $maxAttempts, fn () => true, $decaySeconds)) {
            throw new HttpResponseException(
                response()->json(['error' => 'rate_limit_exceeded'], 429),
            );
        }
    }

    protected function sanitizeBody(string $body): string
    {
        $stripped = strip_tags($body);
        $trimmed = trim($stripped);

        return mb_substr($trimmed, 0, 2000);
    }

    protected function withCors(JsonResponse $response): JsonResponse
    {
        return $response
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type');
    }
}
