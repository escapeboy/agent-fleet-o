<?php

declare(strict_types=1);

namespace App\Livewire\Releases;

use App\Domain\Release\Models\Release;
use App\Domain\Release\Services\ArtifactVersionDiff;
use App\Models\Artifact;
use App\Models\ArtifactVersion;
use Livewire\Component;

class ReleaseDiffPage extends Component
{
    public Release $release;

    public ?string $leftArtifactId = null;

    public ?string $rightArtifactId = null;

    public function mount(Release $release): void
    {
        $this->release = $release;

        $attached = $release->artifacts()->orderByPivot('sort_order')->get();
        if ($attached->count() >= 2) {
            $this->leftArtifactId = $attached[0]->id;
            $this->rightArtifactId = $attached[1]->id;
        } elseif ($attached->count() === 1) {
            $this->leftArtifactId = $attached[0]->id;
            $this->rightArtifactId = $attached[0]->id;
        }
    }

    private function resolveContent(?string $artifactId): string
    {
        if ($artifactId === null) {
            return '';
        }

        $artifact = Artifact::find($artifactId);
        if (! $artifact) {
            return '';
        }

        $version = ArtifactVersion::where('artifact_id', $artifact->id)
            ->where('version', $artifact->current_version)
            ->first();

        return (string) ($version?->content ?? '');
    }

    public function render()
    {
        $left = $this->resolveContent($this->leftArtifactId);
        $right = $this->resolveContent($this->rightArtifactId);

        $segments = app(ArtifactVersionDiff::class)->diff($left, $right);

        $leftArtifact = $this->leftArtifactId ? Artifact::find($this->leftArtifactId) : null;
        $rightArtifact = $this->rightArtifactId ? Artifact::find($this->rightArtifactId) : null;

        return view('livewire.releases.release-diff-page', [
            'segments' => $segments,
            'leftArtifact' => $leftArtifact,
            'rightArtifact' => $rightArtifact,
            'attached' => $this->release->artifacts()->orderByPivot('sort_order')->get(),
        ])->layout('layouts.app', ['header' => 'Diff: '.$this->release->name]);
    }
}
