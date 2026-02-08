<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlobalSetting extends Model
{
    public $incrementing = false;
    public $timestamps = false;

    protected $primaryKey = 'key';
    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'updated_at' => 'datetime',
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::find($key);

        return $setting?->value ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_at' => now()],
        );
    }
}
