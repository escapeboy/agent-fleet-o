<?php

namespace App\Domain\ProductGraph\Models;

use App\Domain\ProductGraph\Enums\ChangeStatus;
use App\Domain\ProductGraph\Enums\ChangeType;
use App\Domain\Shared\Traits\BelongsToTeam;
use Database\Factories\Domain\ProductGraph\ProductGraphChangeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property ChangeType $change_type
 * @property string|null $target_id
 * @property array<string, mixed> $payload
 * @property ChangeStatus $status
 * @property string $proposed_by_label
 * @property string|null $proposed_by_user_id
 * @property string|null $reviewed_by_user_id
 * @property string|null $review_note
 * @property string|null $applied_ref_id
 * @property-read Carbon|null $created_at
 */
class ProductGraphChange extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected $fillable = [
        'team_id',
        'change_type',
        'target_id',
        'payload',
        'status',
        'proposed_by_label',
        'proposed_by_user_id',
        'reviewed_by_user_id',
        'review_note',
        'applied_ref_id',
    ];

    protected function casts(): array
    {
        return [
            'change_type' => ChangeType::class,
            'status' => ChangeStatus::class,
            'payload' => 'array',
        ];
    }

    protected static function newFactory(): ProductGraphChangeFactory
    {
        return ProductGraphChangeFactory::new();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ChangeStatus::Pending->value);
    }
}
