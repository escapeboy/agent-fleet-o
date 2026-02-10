<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Models\Experiment;
use Illuminate\Support\Str;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ArtifactList extends Component
{
    public Experiment $experiment;
    public ?string $expandedArtifactId = null;

    public function toggleArtifact(string $artifactId): void
    {
        $this->expandedArtifactId = $this->expandedArtifactId === $artifactId ? null : $artifactId;
    }

    public function downloadArtifact(string $artifactId, ?int $version = null): StreamedResponse
    {
        $artifact = $this->experiment->artifacts()->findOrFail($artifactId);

        $artifactVersion = $version
            ? $artifact->versions()->where('version', $version)->firstOrFail()
            : $artifact->versions()->orderByDesc('version')->firstOrFail();

        $extension = $this->resolveExtension($artifact->type);
        $filename = Str::slug($artifact->name) . "-v{$artifactVersion->version}.{$extension}";

        $content = is_string($artifactVersion->content)
            ? $artifactVersion->content
            : json_encode($artifactVersion->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $filename, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    private function resolveExtension(string $type): string
    {
        return match ($type) {
            'code', 'frontend', 'backend', 'frontend_landing_page', 'backend_tracking' => 'html',
            'config', 'deployment', 'deployment_config' => 'json',
            'seo', 'plan', 'strategy', 'research', 'seo_keyword_pack', 'task_breakdown_plan', 'sales_strategy_doc', 'product_niche_analysis' => 'md',
            default => 'txt',
        };
    }

    public function render()
    {
        $artifacts = $this->experiment->artifacts()
            ->with('versions')
            ->latest()
            ->get();

        return view('livewire.experiments.artifact-list', [
            'artifacts' => $artifacts,
        ]);
    }
}
