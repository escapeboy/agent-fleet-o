<?php

namespace App\Domain\Telegram\Models;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Infrastructure\Encryption\Casts\TeamEncryptedString;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property string|null $bot_token
 * @property string|null $bot_username
 * @property string|null $bot_name
 * @property string $routing_mode
 * @property string|null $default_project_id
 * @property bool $webhook_mode
 * @property string|null $webhook_secret
 * @property string $status
 * @property string|null $last_error
 * @property Carbon|null $last_message_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project|null $defaultProject
 * @property-read Collection<int, TelegramChatBinding> $chatBindings
 */
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

    protected $hidden = [
        'bot_token',
        'webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'bot_token' => TeamEncryptedString::class,
            'webhook_secret' => TeamEncryptedString::class,
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
