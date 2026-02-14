<?php

namespace App\Domain\Crew\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Enums\CrewMemberRole;
use Database\Factories\Domain\Crew\CrewMemberFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrewMember extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory()
    {
        return CrewMemberFactory::new();
    }

    protected $fillable = [
        'crew_id',
        'agent_id',
        'role',
        'sort_order',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'role' => CrewMemberRole::class,
            'config' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function crew(): BelongsTo
    {
        return $this->belongsTo(Crew::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
