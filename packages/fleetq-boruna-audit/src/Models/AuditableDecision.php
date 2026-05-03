<?php

namespace FleetQ\BorunaAudit\Models;

use Database\Factories\Domain\BorunaAudit\AuditableDecisionFactory;
use FleetQ\BorunaAudit\Enums\DecisionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property DecisionStatus $status
 * @property array|null $inputs
 * @property array|null $outputs
 * @property array|null $evidence
 * @property bool $shadow_mode
 * @property float|null $shadow_discrepancy
 */
class AuditableDecision extends Model
{
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        // Mirrors App\Domain\Shared\Scopes\TeamScope without coupling to the base app.
        // Applies team_id isolation on every query, matching the project-wide tenancy contract.
        static::addGlobalScope('team', function (Builder $builder) {
            $insideTest = app()->runningUnitTests()
                || defined('PHPUNIT_COMPOSER_INSTALL')
                || defined('__PHPUNIT_PHAR__');

            if (app()->runningInConsole() && ! $insideTest && ! app()->bound('mcp.active')) {
                return;
            }

            $user = auth()->user();

            if ($user && $user->current_team_id) {
                $builder->where('boruna_auditable_decisions.team_id', $user->current_team_id);
            } elseif ($user) {
                $builder->whereRaw('1=0');
            }
        });
    }

    protected static function newFactory()
    {
        return AuditableDecisionFactory::new();
    }

    protected $table = 'boruna_auditable_decisions';

    protected $fillable = [
        'team_id',
        'subject_type',
        'subject_id',
        'workflow_name',
        'workflow_version',
        'run_id',
        'bundle_path',
        'status',
        'inputs',
        'outputs',
        'evidence',
        'shadow_mode',
        'shadow_discrepancy',
    ];

    protected function casts(): array
    {
        return [
            'status' => DecisionStatus::class,
            'inputs' => 'array',
            'outputs' => 'array',
            'evidence' => 'array',
            'shadow_mode' => 'boolean',
            'shadow_discrepancy' => 'float',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<BundleVerification, $this> */
    public function verifications(): HasMany
    {
        return $this->hasMany(BundleVerification::class, 'auditable_decision_id');
    }

    /** @return HasMany<BundleVerification, $this> */
    public function latestVerification(): HasMany
    {
        return $this->hasMany(BundleVerification::class, 'auditable_decision_id')
            ->latest('checked_at')
            ->limit(1);
    }
}
