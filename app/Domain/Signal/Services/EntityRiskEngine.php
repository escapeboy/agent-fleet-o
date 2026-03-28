<?php

namespace App\Domain\Signal\Services;

use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Signal\Contracts\EntityRule;
use App\Domain\Signal\DTOs\ContactRiskContext;
use App\Domain\Signal\Rules\E01DisposableEmailRule;
use App\Domain\Signal\Rules\E02NoContactDataRule;
use App\Domain\Signal\Rules\I01HighRiskIpRule;
use App\Domain\Signal\Rules\I02TorVpnIpRule;
use App\Domain\Signal\Rules\S01BurstActivityRule;
use App\Domain\Signal\Rules\S02NoVerifiedChannelRule;
use App\Infrastructure\Security\DTOs\IpReputationResult;
use App\Infrastructure\Security\IpReputationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class EntityRiskEngine
{
    /** @var EntityRule[] */
    private array $rules;

    public function __construct(
        private readonly IpReputationService $ipReputation,
    ) {
        $this->rules = [
            new E01DisposableEmailRule,
            new E02NoContactDataRule,
            new I01HighRiskIpRule,
            new I02TorVpnIpRule,
            new S01BurstActivityRule,
            new S02NoVerifiedChannelRule,
        ];
    }

    /**
     * Evaluate all rules for a contact and persist the updated risk score.
     *
     * @return array{score: int, flags: array<int, array<string, mixed>>}
     */
    public function evaluate(ContactIdentity $contact): array
    {
        $context = $this->buildContext($contact);

        $score = 0;
        $flags = [];

        foreach ($this->rules as $rule) {
            if ($rule->evaluate($context)) {
                $score += $rule->weight();
                $flags[] = [
                    'rule' => $rule->name(),
                    'label' => $rule->label(),
                    'weight' => $rule->weight(),
                    'triggered_at' => Carbon::now()->toIso8601String(),
                ];
            }
        }

        $contact->update([
            'risk_score' => $score,
            'risk_flags' => $flags,
            'risk_evaluated_at' => Carbon::now(),
        ]);

        return compact('score', 'flags');
    }

    private function buildContext(ContactIdentity $contact): ContactRiskContext
    {
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        $signals = $contact->signals()
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->get();

        // Resolve IP reputation from the most recent signal that has a source IP.
        $ipReputation = $this->resolveIpReputation($signals);

        $channels = $contact->channels()->get();
        $channelTypes = $channels->pluck('channel')->unique()->values()->toArray();
        $hasVerifiedChannel = $channels->contains('verified', true);

        return new ContactRiskContext(
            contact: $contact,
            ipReputation: $ipReputation,
            recentSignals: $signals->toArray(),
            signalCount: $signals->count(),
            channelTypes: $channelTypes,
            hasVerifiedChannel: $hasVerifiedChannel,
        );
    }

    private function resolveIpReputation(Collection $signals): ?IpReputationResult
    {
        // Signals store source IP in the `source` column (set by WebhookConnector).
        $ip = $signals
            ->sortByDesc('created_at')
            ->pluck('source_identifier')
            ->filter(fn ($s) => $s && filter_var($s, FILTER_VALIDATE_IP))
            ->first();

        if ($ip === null) {
            return null;
        }

        if ($this->ipReputation->isPrivate($ip)) {
            return null;
        }

        return $this->ipReputation->check($ip);
    }
}
