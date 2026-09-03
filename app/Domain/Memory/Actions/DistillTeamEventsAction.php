<?php

namespace App\Domain\Memory\Actions;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Memory\Enums\MemoryTier;
use App\Domain\Memory\Enums\MemoryVisibility;
use App\Domain\Shared\Exceptions\AiAccessUnavailableException;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Exceptions\VpsLocalAgentException;
use App\Infrastructure\AI\Services\ProviderResolver;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Distils one team's recent audit-entry stream into a small set of durable,
 * high-signal facts and stores them as an `events_digest` memory. Borrowed
 * from CraftBot's nightly EVENT_UNPROCESSED.md -> MEMORY.md pass.
 *
 * AuditEntry is the canonical event log — experiment transitions, approval
 * decisions, budget events and agent events all flow into it via the Audit
 * domain listeners, so it alone covers the broader raw event stream.
 */
class DistillTeamEventsAction
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You distil a team's recent platform activity log into a few durable, high-signal
facts worth remembering long-term. Output a short bullet list of distinct facts —
patterns, notable outcomes, recurring failures, decisions. Drop routine noise.
No preamble, no closing remarks. If nothing is worth remembering, output nothing.
PROMPT;

    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly StoreMemoryAction $storeMemory,
        private readonly ProviderResolver $providerResolver,
    ) {}

    /**
     * @return array{team_id: string, events: int, stored: int, window_start: string, dry_run: bool}
     */
    public function execute(string $teamId, ?CarbonInterface $since = null, bool $dryRun = false): array
    {
        $team = Team::find($teamId);
        $windowStart = $since
            ?? $this->watermark($team)
            ?? now()->subHours((int) config('memory.distillation.window_hours', 24));

        $events = $this->gatherEvents($teamId, $windowStart);

        $result = [
            'team_id' => $teamId,
            'events' => $events->count(),
            'stored' => 0,
            'window_start' => $windowStart->toIso8601String(),
            'dry_run' => $dryRun,
        ];

        if ($events->isEmpty() || $dryRun) {
            return $result;
        }

        // Catch the "no provider" path the AI Gateway throws when the team has
        // no BYOK and the platform has no key for the distillation provider.
        // Without this guard the hourly cron fires hundreds of Sentry errors
        // per night on free-tier teams. Sentry issue FLEETQ-81.
        //
        // DistillTeamEventsJob's pre-flight gate (TeamAiAccessChecker::canUseAi)
        // is provider-AGNOSTIC — it passes any team holding *a* credential —
        // while distil() below demands one specific provider. A BYOK team whose
        // only key is for a different provider therefore clears the gate and
        // then throws AiAccessUnavailableException here. That is expected
        // backpressure, not a defect, so it must be skipped like the
        // fallback-chain case instead of churning failed_jobs and Sentry
        // forever. (#824/#847/#1035/#1064)
        //
        // VpsLocalAgentException попада в същата категория: екип, чийто default
        // сочи `claude-code-vps`, но не е в whitelist-а (или е ударил
        // concurrency cap-а), не е дефект — това е същият backpressure. Хвърля
        // се СЪЗНАТЕЛНО нагоре при интерактивна работа (там потребителят ТРЯБВА
        // да види съобщението), затова НЕ се филтрира в BeforeSendFilter, а се
        // поглъща само тук, във фоновата нощна задача. (FLEETQ-BJ)
        try {
            $summary = $this->distil($events, $team, $teamId);
        } catch (RuntimeException $e) {
            if (! $e instanceof AiAccessUnavailableException
                && ! $e instanceof VpsLocalAgentException
                && ! str_contains($e->getMessage(), 'No available providers in fallback chain')) {
                throw $e;
            }
            Log::info('DistillTeamEventsAction: skipping team (no provider credentials)', [
                'team_id' => $teamId,
                'error' => $e->getMessage(),
            ]);
            $this->advanceWatermark($team);

            return $result;
        }

        if ($summary === '') {
            // Nothing worth remembering — still advance the watermark so the
            // window doesn't keep re-scanning the same consumed events.
            $this->advanceWatermark($team);

            return $result;
        }

        $stored = $this->storeMemory->execute(
            teamId: $teamId,
            agentId: null,
            content: $summary,
            sourceType: 'events_digest',
            metadata: [
                'event_count' => $events->count(),
                'window_start' => $windowStart->toIso8601String(),
            ],
            confidence: 0.8,
            importance: 0.6,
            visibility: MemoryVisibility::Team,
            tier: MemoryTier::Working,
        );

        $this->advanceWatermark($team);
        $result['stored'] = count($stored);

        return $result;
    }

    /**
     * @return Collection<int, AuditEntry>
     */
    private function gatherEvents(string $teamId, CarbonInterface $windowStart): Collection
    {
        return AuditEntry::query()
            ->withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('created_at', '>', $windowStart)
            ->orderByDesc('created_at')
            ->limit((int) config('memory.distillation.max_events', 200))
            ->get(['event', 'subject_type', 'created_at']);
    }

    /**
     * @param  Collection<int, AuditEntry>  $events
     */
    private function distil(Collection $events, ?Team $team, string $teamId): string
    {
        $log = $events
            ->sortBy('created_at')
            ->map(fn (AuditEntry $e) => sprintf(
                '- %s: %s%s',
                optional($e->created_at)->toDateTimeString() ?? '',
                $e->event,
                $e->subject_type ? ' ('.class_basename($e->subject_type).')' : '',
            ))
            ->implode("\n");

        $resolved = $this->resolveDistillationModel($team);

        $response = $this->gateway->complete(new AiRequestDTO(
            provider: $resolved['provider'],
            model: $resolved['model'],
            systemPrompt: self::SYSTEM_PROMPT,
            userPrompt: "Recent activity log:\n\n".$log,
            maxTokens: 512,
            teamId: $teamId,
            purpose: 'memory.distill_events',
            temperature: 0.2,
        ));

        return trim($response->content);
    }

    /**
     * Изборът на ЕКИПА е водещ; евтиният дестилационен модел е само fallback.
     *
     * Дефект FLEETQ-BJ: този метод четеше `memory.distillation.model` първо и
     * така заобикаляше цялата каскада skill → agent → team → GlobalSetting →
     * платформа. На production стойността е `groq/openai/gpt-oss-120b`, чийто
     * префикс носи и доставчика — така всеки екип, независимо от собствения си
     * BYOK доставчик, беше пращан към Groq и всяка нощ в 01:31 гърмеше с
     * „Groq Error [401]: Invalid API Key".
     *
     * Затова: първо `resolveWithSource(team:)`, и само когато екипът НЕ е
     * изразил предпочитание налагаме дестилационния модел. „Няма предпочитание"
     * покрива и двата платформени източника — `platform` (GlobalSetting) и
     * `config` (конфигурационният fallback). На production GlobalSetting е NULL,
     * тоест source е именно `config`; ако гледахме само `platform`, клонът
     * никога нямаше да се задейства в prod и дестилацията щеше да върви на
     * скъпия платформен модел вместо на евтиния haiku.
     *
     * @return array{provider: string, model: string}
     */
    private function resolveDistillationModel(?Team $team): array
    {
        $resolved = $this->providerResolver->resolveWithSource(team: $team);

        if (! in_array($resolved['source'], ['platform', 'config'], true)) {
            return [
                'provider' => (string) $resolved['provider'],
                'model' => (string) $resolved['model'],
            ];
        }

        // Моделът носи собствен префикс за доставчик ("anthropic/claude-haiku-4-5"),
        // за да не бъде сдвоен с чужд доставчик (което дава 400 на gateway-и без
        // Anthropic модели). Стойност без префикс пада към отделния
        // `distillation.provider` ключ.
        $configured = (string) config('memory.distillation.model', 'anthropic/claude-haiku-4-5');
        [$provider, $model] = str_contains($configured, '/')
            ? explode('/', $configured, 2)
            : [(string) config('memory.distillation.provider', 'anthropic'), $configured];

        return ['provider' => $provider, 'model' => $model];
    }

    private function watermark(?Team $team): ?CarbonInterface
    {
        $iso = $team?->settings['memory']['last_event_distill_at'] ?? null;

        return is_string($iso) ? Carbon::parse($iso) : null;
    }

    private function advanceWatermark(?Team $team): void
    {
        if ($team === null) {
            return;
        }

        $settings = $team->settings ?? [];
        $settings['memory']['last_event_distill_at'] = now()->toIso8601String();
        $team->update(['settings' => $settings]);
    }
}
