<?php

namespace App\Infrastructure\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Single gateway for tenant-scoped file storage.
 *
 * Every object lives under {prefix}/{team_id}/{category}/... so that the
 * owning team is encoded in the key itself — SecureFileController re-derives
 * the team from the key to authorize access. The public disk is written to
 * ONLY when visibility === 'public'; call sites never choose the disk.
 */
class TenantStorageManager
{
    public const VISIBILITY_PRIVATE = 'private';

    public const VISIBILITY_PUBLIC = 'public';

    /**
     * Store an uploaded file (or a local path) under the team's prefix and
     * return the full storage key.
     */
    public function put(
        UploadedFile|string $file,
        string $category,
        string $visibility = self::VISIBILITY_PRIVATE,
        ?string $teamId = null,
        ?string $filename = null,
    ): string {
        $teamId = $teamId ?? $this->resolveTeamId();
        $directory = $this->directory($teamId, $category);
        $name = $filename ?? $this->generateName($file);

        $source = $file instanceof UploadedFile ? $file : new File($file);

        $this->disk($visibility)->putFileAs($directory, $source, $name);

        return "{$directory}/{$name}";
    }

    /**
     * Resolve the configured disk for a visibility level.
     */
    public function disk(string $visibility = self::VISIBILITY_PRIVATE): Filesystem
    {
        return Storage::disk($this->diskName($visibility));
    }

    /**
     * Presigned (or local) temporary URL for a private object — media preview.
     */
    public function temporaryUrl(string $key, ?int $minutes = null): string
    {
        $minutes = $minutes ?? (int) config('tenant_storage.temporary_url_minutes', 15);

        return $this->disk(self::VISIBILITY_PRIVATE)
            ->temporaryUrl($key, now()->addMinutes($minutes));
    }

    /**
     * Unsigned public URL for an object stored with public visibility.
     */
    public function publicUrl(string $key): string
    {
        return $this->disk(self::VISIBILITY_PUBLIC)->url($key);
    }

    public function delete(string $key, string $visibility = self::VISIBILITY_PRIVATE): void
    {
        $this->disk($visibility)->delete($key);
    }

    public function get(string $key, string $visibility = self::VISIBILITY_PRIVATE): ?string
    {
        return $this->disk($visibility)->get($key);
    }

    /**
     * The team prefix for a key — `{prefix}/{team_id}`. Used by the serving
     * controller to authorize access without trusting caller-supplied paths.
     */
    public function teamPrefix(string $teamId): string
    {
        return config('tenant_storage.prefix', 'tenants')."/{$teamId}";
    }

    /**
     * Extract the team_id segment from a tenant key, or null if it does not
     * match the {prefix}/{team_id}/... shape.
     */
    public function teamIdFromKey(string $key): ?string
    {
        $prefix = config('tenant_storage.prefix', 'tenants');
        $key = ltrim($key, '/');

        if (! Str::startsWith($key, $prefix.'/')) {
            return null;
        }

        $segments = explode('/', $key);

        return $segments[1] ?? null;
    }

    private function directory(string $teamId, string $category): string
    {
        return $this->teamPrefix($teamId).'/'.trim($category, '/');
    }

    private function generateName(UploadedFile|string $file): string
    {
        $ext = $file instanceof UploadedFile
            ? ($file->getClientOriginalExtension() ?: $file->guessExtension())
            : pathinfo($file, PATHINFO_EXTENSION);

        $uuid = (string) Str::uuid();

        return $ext ? "{$uuid}.{$ext}" : $uuid;
    }

    public function diskName(string $visibility): string
    {
        return match ($visibility) {
            self::VISIBILITY_PUBLIC => config('tenant_storage.public_disk', 'public'),
            self::VISIBILITY_PRIVATE => config('tenant_storage.private_disk', 'local'),
            default => throw new RuntimeException("Unknown storage visibility: {$visibility}"),
        };
    }

    private function resolveTeamId(): string
    {
        $teamId = auth()->user()?->currentTeam?->id;

        if (! $teamId && app()->bound('ai.current_team_id')) {
            $teamId = app('ai.current_team_id');
        }

        if (! $teamId && app()->bound('mcp.team_id')) {
            $teamId = app('mcp.team_id');
        }

        if (! $teamId) {
            throw new RuntimeException(
                'TenantStorageManager: cannot resolve team_id for storage access. '
                .'Pass an explicit $teamId in queue/MCP contexts.'
            );
        }

        return $teamId;
    }
}
