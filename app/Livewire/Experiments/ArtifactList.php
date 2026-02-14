<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Enums\ExperimentTaskStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Services\ArtifactContentResolver;
use Illuminate\Support\Str;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ArtifactList extends Component
{
    public Experiment $experiment;

    public ?string $selectedArtifactId = null;

    public ?int $selectedVersion = null;

    public ?string $previewContent = null;

    public bool $showFullscreen = false;

    public function mount(): void
    {
        // Auto-select first artifact if any exist
        $first = $this->experiment->artifacts()->orderBy('created_at')->first();
        if ($first) {
            $this->selectArtifact($first->id);
        }
    }

    public function selectArtifact(string $id): void
    {
        $this->selectedArtifactId = $id;
        $this->selectedVersion = null;
        $this->loadContent();
    }

    public function selectVersion(int $version): void
    {
        $this->selectedVersion = $version;
        $this->loadContent();
    }

    public function toggleFullscreen(): void
    {
        $this->showFullscreen = ! $this->showFullscreen;
    }

    public function downloadArtifact(): StreamedResponse
    {
        $artifact = $this->experiment->artifacts()->findOrFail($this->selectedArtifactId);

        $version = $this->selectedVersion
            ? $artifact->versions()->where('version', $this->selectedVersion)->firstOrFail()
            : $artifact->versions()->orderByDesc('version')->firstOrFail();

        $extension = ArtifactContentResolver::extension($artifact->type);
        $mime = ArtifactContentResolver::mimeType($artifact->type);
        $filename = Str::slug($artifact->name)."-v{$version->version}.{$extension}";

        $content = is_string($version->content)
            ? $version->content
            : json_encode($version->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $filename, [
            'Content-Type' => $mime,
        ]);
    }

    public function getSelectedArtifactProperty()
    {
        if (! $this->selectedArtifactId) {
            return null;
        }

        return $this->experiment->artifacts()->find($this->selectedArtifactId);
    }

    public function getContentCategoryProperty(): string
    {
        $artifact = $this->selectedArtifact;

        if (! $artifact) {
            return 'text';
        }

        return ArtifactContentResolver::category($artifact->type, $this->previewContent);
    }

    public function getHighlightLanguageProperty(): string
    {
        $artifact = $this->selectedArtifact;

        if (! $artifact) {
            return 'plaintext';
        }

        return ArtifactContentResolver::highlightLanguage($artifact->type);
    }

    public function render()
    {
        // Load artifact metadata without content (performance: no eager-loading text blobs)
        $artifacts = $this->experiment->artifacts()
            ->withCount('versions')
            ->with(['versions' => fn ($q) => $q->select('id', 'artifact_id', 'version', 'metadata', 'created_at')->orderByDesc('version')])
            ->orderBy('created_at')
            ->get();

        // Failed build tasks (no artifact record created)
        $failedTasks = $this->experiment->tasks()
            ->where('stage', 'building')
            ->where('status', ExperimentTaskStatus::Failed)
            ->get();

        return view('livewire.experiments.artifact-list', [
            'artifacts' => $artifacts,
            'failedTasks' => $failedTasks,
        ]);
    }

    private function loadContent(): void
    {
        if (! $this->selectedArtifactId) {
            $this->previewContent = null;

            return;
        }

        $artifact = $this->experiment->artifacts()->find($this->selectedArtifactId);

        if (! $artifact) {
            $this->previewContent = null;

            return;
        }

        $version = $this->selectedVersion
            ? $artifact->versions()->where('version', $this->selectedVersion)->first()
            : $artifact->versions()->orderByDesc('version')->first();

        if (! $version) {
            $this->previewContent = null;

            return;
        }

        $this->selectedVersion = $version->version;

        $this->previewContent = is_string($version->content)
            ? $version->content
            : json_encode($version->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
