<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Models\Experiment;
use Livewire\Component;

class ArtifactList extends Component
{
    public Experiment $experiment;
    public ?string $expandedArtifactId = null;

    public function toggleArtifact(string $artifactId): void
    {
        $this->expandedArtifactId = $this->expandedArtifactId === $artifactId ? null : $artifactId;
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
