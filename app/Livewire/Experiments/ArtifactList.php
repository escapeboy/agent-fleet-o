<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Enums\ExperimentTaskStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Services\ArtifactContentResolver;
use App\Models\Artifact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ArtifactList extends Component
{
    /** The model that owns the artifacts (Experiment, CrewExecution, or ProjectRun) */
    public Model $artifactOwner;

    /** Whether to show failed experiment build tasks (only for Experiment owners) */
    public bool $showFailedTasks = false;

    public ?string $selectedArtifactId = null;

    public ?int $selectedVersion = null;

    public ?string $previewContent = null;

    public bool $showFullscreen = false;

    public function mount(Model $artifactOwner, bool $showFailedTasks = false): void
    {
        $this->artifactOwner = $artifactOwner;
        $this->showFailedTasks = $showFailedTasks && $artifactOwner instanceof Experiment;

        // Auto-select first artifact if any exist
        $first = $this->artifactOwner->artifacts()->orderBy('created_at')->first();
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
        $artifact = $this->artifactOwner->artifacts()->findOrFail($this->selectedArtifactId);

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

    public function downloadAllAsZip(): StreamedResponse
    {
        $artifacts = $this->artifactOwner->artifacts()
            ->with(['versions' => fn ($q) => $q->orderByDesc('version')->limit(1)])
            ->get();

        $ownerName = method_exists($this->artifactOwner, 'getRouteKey')
            ? Str::slug(class_basename($this->artifactOwner).'-'.$this->artifactOwner->getRouteKey())
            : 'artifacts';

        return response()->streamDownload(function () use ($artifacts) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'artifacts_');
            $zip = new \ZipArchive;
            $zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

            foreach ($artifacts as $artifact) {
                $version = $artifact->versions->first();
                if (! $version) {
                    continue;
                }

                $ext = ArtifactContentResolver::extension($artifact->type);
                $content = is_string($version->content)
                    ? $version->content
                    : json_encode($version->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

                $zip->addFromString(
                    Str::slug($artifact->name)."-v{$version->version}.{$ext}",
                    $content,
                );
            }

            $zip->close();
            readfile($tmpFile);
            unlink($tmpFile);
        }, $ownerName.'-artifacts.zip', [
            'Content-Type' => 'application/zip',
        ]);
    }

    public function getSelectedArtifactProperty()
    {
        if (! $this->selectedArtifactId) {
            return null;
        }

        return $this->artifactOwner->artifacts()->find($this->selectedArtifactId);
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
        $artifacts = $this->artifactOwner->artifacts()
            ->withCount('versions')
            ->with(['versions' => fn ($q) => $q->select('id', 'artifact_id', 'version', 'metadata', 'created_at')->orderByDesc('version')])
            ->orderBy('created_at')
            ->get();

        $failedTasks = collect();
        if ($this->showFailedTasks && $this->artifactOwner instanceof Experiment) {
            $failedTasks = $this->artifactOwner->tasks()
                ->where('stage', 'building')
                ->where('status', ExperimentTaskStatus::Failed)
                ->get();
        }

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

        $artifact = $this->artifactOwner->artifacts()->find($this->selectedArtifactId);

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
