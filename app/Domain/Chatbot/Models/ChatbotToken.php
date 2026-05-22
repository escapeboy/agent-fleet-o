<?php

namespace App\Domain\Chatbot\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $chatbot_id
 * @property string $team_id
 * @property string $name
 * @property string $token_prefix
 * @property string $token_hash
 * @property array|null $allowed_origins
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 */
class ChatbotToken extends Model
{
    use HasUuids;

    protected $fillable = [
        'chatbot_id',
        'team_id',
        'name',
        'token_prefix',
        'token_hash',
        'allowed_origins',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'allowed_origins' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }

    public function isValid(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function revoke(): void
    {
        $this->update(['revoked_at' => now()]);
    }

    public function isAllowedOrigin(?string $origin): bool
    {
        if (empty($this->allowed_origins)) {
            return true; // unrestricted
        }

        if ($origin === null) {
            return false;
        }

        return in_array($origin, $this->allowed_origins, true);
    }

    public static function findByToken(string $plaintext): ?self
    {
        $hash = hash('sha256', $plaintext);

        return self::where('token_hash', $hash)
            ->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();
    }
}
