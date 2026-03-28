<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Shared\Models\ContactIdentity;
use Illuminate\Support\Facades\Auth;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;

class SecurityTools
{
    /**
     * @return array<PrismToolObject>
     */
    public static function tools(): array
    {
        return [
            self::getContactRiskScore(),
            self::listHighRiskContacts(),
        ];
    }

    private static function getContactRiskScore(): PrismToolObject
    {
        return PrismTool::as('get_contact_risk_score')
            ->for('Get the current risk score, triggered rule flags, and evaluation timestamp for a specific contact identity')
            ->withStringParameter('contact_id', 'The UUID of the contact identity')
            ->using(function (string $contact_id) {
                $teamId = Auth::user()?->current_team_id;

                if (! $teamId) {
                    return json_encode(['error' => 'No current team.']);
                }

                $contact = ContactIdentity::withoutGlobalScopes()
                    ->where('team_id', $teamId)
                    ->find($contact_id);

                if (! $contact) {
                    return json_encode(['error' => 'Contact not found']);
                }

                return json_encode([
                    'contact_id' => $contact->id,
                    'display_name' => $contact->display_name,
                    'risk_score' => $contact->risk_score,
                    'risk_flags' => $contact->risk_flags ?? [],
                    'risk_evaluated_at' => $contact->risk_evaluated_at?->toIso8601String(),
                ]);
            });
    }

    private static function listHighRiskContacts(): PrismToolObject
    {
        return PrismTool::as('list_high_risk_contacts')
            ->for('List contact identities whose risk score is at or above a threshold, ordered by highest score first')
            ->withIntegerParameter('threshold', 'Minimum risk score to include (default: 30)')
            ->withIntegerParameter('limit', 'Maximum number of results to return (default: 25, max: 100)')
            ->using(function (int $threshold = 30, int $limit = 25) {
                $teamId = Auth::user()?->current_team_id;

                if (! $teamId) {
                    return json_encode(['error' => 'No current team.']);
                }

                $threshold = min(max($threshold, 0), 1000);
                $limit = min($limit, 100);

                $contacts = ContactIdentity::withoutGlobalScopes()
                    ->where('team_id', $teamId)
                    ->where('risk_score', '>=', $threshold)
                    ->orderByDesc('risk_score')
                    ->limit($limit)
                    ->get(['id', 'display_name', 'email', 'phone', 'risk_score', 'risk_flags', 'risk_evaluated_at']);

                return json_encode([
                    'threshold' => $threshold,
                    'total' => $contacts->count(),
                    'contacts' => $contacts->map(fn ($c) => [
                        'id' => $c->id,
                        'display_name' => $c->display_name,
                        'email' => $c->email,
                        'phone' => $c->phone,
                        'risk_score' => $c->risk_score,
                        'risk_flags' => $c->risk_flags ?? [],
                        'risk_evaluated_at' => $c->risk_evaluated_at?->toIso8601String(),
                    ])->values()->toArray(),
                ]);
            });
    }
}
