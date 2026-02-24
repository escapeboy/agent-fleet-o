<?php

namespace App\Domain\Telegram\Models;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramBot extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'bot_token',
        'bot_username',
        'bot_name',
        'routing_mode',
        'default_project_id',
        'webhook_mode',
        'webhook_secret',
        'status',
        'last_error',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'bot_token' => 'encrypted',
            'webhook_mode' => 'boolean',
            'last_message_at' => 'datetime',
        ];
    }

    public function defaultProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'default_project_id');
    }

    public function chatBindings(): HasMany
    {
        return $this->hasMany(TelegramChatBinding::class, 'team_id', 'team_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function apiUrl(string $method): string
    {
        return "https://api.telegram.org/bot{$this->bot_token}/{$method}";
    }
}
