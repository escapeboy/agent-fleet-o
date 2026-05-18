<?php

namespace App\Domain\GitRepository\Services;

use App\Domain\Memory\Models\Memory;
use App\Models\Artifact;
use BackedEnum;
use Illuminate\Support\Str;

/**
 * Renders FleetQ context entities (artifacts, memory) as plain markdown files
 * with YAML frontmatter — the on-disk shape of the git-backed context filesystem.
 *
 * Kanwas-inspired sprint — "every document is a plain .md file".
 */
class ContextMarkdownRenderer
{
    /**
     * @return array{path: string, content: string}|null Null when the artifact has no version content.
     */
    public function artifact(Artifact $artifact, string $prefix): ?array
    {
        $version = $artifact->versions->firstWhere('version', $artifact->current_version);
        $content = (string) ($version?->getAttribute('content') ?? '');

        if (trim($content) === '') {
            return null;
        }

        $frontmatter = $this->frontmatter([
            'name' => $artifact->name,
            'type' => $artifact->type,
            'version' => $artifact->current_version,
            'artifact_id' => $artifact->id,
            'experiment_id' => $artifact->experiment_id,
        ]);

        $folder = $this->folder($artifact->type ?: 'misc');
        $slug = $this->slug($artifact->name, $artifact->id);

        return [
            'path' => rtrim($prefix, '/').'/'.$folder.'/'.$slug.'.md',
            'content' => $frontmatter."\n".$content."\n",
        ];
    }

    /**
     * @return array{path: string, content: string}
     */
    public function memory(Memory $memory, string $prefix): array
    {
        $frontmatter = $this->frontmatter([
            'memory_id' => $memory->id,
            'tier' => $this->enumValue($memory->tier),
            'category' => $this->enumValue($memory->category),
            'confidence' => $memory->confidence,
            'topic' => $memory->topic,
            'tags' => $memory->tags,
        ]);

        $folder = $this->folder($this->enumValue($memory->tier) ?: 'general');
        $slug = $this->slug($memory->topic ?: 'memory', $memory->id);

        return [
            'path' => rtrim($prefix, '/').'/'.$folder.'/'.$slug.'.md',
            'content' => $frontmatter."\n".((string) $memory->content)."\n",
        ];
    }

    private function folder(string $raw): string
    {
        $folder = Str::slug($raw);

        return $folder !== '' ? $folder : 'misc';
    }

    private function slug(string $name, string $id): string
    {
        $base = Str::slug(Str::limit($name, 60, ''));

        return ($base !== '' ? $base : 'item').'-'.substr($id, 0, 8);
    }

    /**
     * Resolve a backed enum (or plain string) attribute to its string value.
     */
    private function enumValue(BackedEnum|string|null $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function frontmatter(array $data): string
    {
        $lines = ['---'];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                $value = '['.implode(', ', array_map(fn ($v) => (string) $v, $value)).']';
                $lines[] = $key.': '.$value;

                continue;
            }

            $lines[] = $key.': '.$this->scalar($value);
        }

        $lines[] = '---';

        return implode("\n", $lines)."\n";
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $str = (string) $value;

        // Quote values containing YAML-significant characters.
        if (preg_match('/[:#\[\]{}\n]/', $str) === 1) {
            return '"'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', ' '], $str).'"';
        }

        return $str;
    }
}
