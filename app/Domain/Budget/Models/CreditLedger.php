<?php

namespace App\Domain\Budget\Models;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Models\User;
use Database\Factories\Domain\Budget\CreditLedgerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditLedger extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected $fillable = [
        'team_id',
        'user_id',
        'experiment_id',
        'ai_run_id',
        'type',
        'amount',
        'balance_after',
        'description',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'type' => LedgerType::class,
            'amount' => 'integer',
            'balance_after' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected static function newFactory()
    {
        return CreditLedgerFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class);
    }

    /**
     * Whether the team has billing configured, i.e. any purchased or refunded
     * credits. Budget gates skip credit enforcement when this is false:
     * community/self-hosted installs and not-yet-billed teams never have
     * purchase entries, so enforcing a zero balance would block BYOK and
     * platform-funded calls. Single source of truth for ReserveBudgetAction,
     * CheckBudgetAction and the CheckBudgetAvailable job middleware.
     */
    public static function teamHasPurchasedCredits(string $teamId): bool
    {
        return static::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereIn('type', [LedgerType::Purchase->value, LedgerType::Refund->value])
            ->exists();
    }
}
