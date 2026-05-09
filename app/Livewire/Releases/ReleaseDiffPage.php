<?php

declare(strict_types=1);

namespace App\Livewire\Releases;

use App\Domain\Release\Models\Release;
use App\Domain\Release\Services\Diff\DiffStrategyResolver;
use App\Models\Artifact;
use App\Models\ArtifactVersion;
use Livewire\Component;

class ReleaseDiffPage extends Component
{
    public Release $release;

    public ?string $leftArtifactId = null;

    public ?string $rightArtifactId = null;

    public ?string $baseArtifactId = null;

    public bool $threeWay = false;

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

    public function toggleThreeWay(): void
    {
        $this->threeWay = ! $this->threeWay;
        if (! $this->threeWay) {
            $this->baseArtifactId = null;
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
        $resolver = app(DiffStrategyResolver::class);

        $left = $this->resolveContent($this->leftArtifactId);
        $right = $this->resolveContent($this->rightArtifactId);
        $base = $this->threeWay ? $this->resolveContent($this->baseArtifactId) : null;

        $strategy = $resolver->resolve(null, $left ?: $right);

        $primarySegments = $strategy->diff($left, $right);
        $threeWayLeftSegments = null;
        $threeWayRightSegments = null;
        $conflicts = [];

        if ($this->threeWay && $this->baseArtifactId !== null) {
            $threeWayLeftSegments = $strategy->diff($base, $left);
            $threeWayRightSegments = $strategy->diff($base, $right);
            $conflicts = $this->findConflicts($threeWayLeftSegments, $threeWayRightSegments);
        }

        $leftArtifact = $this->leftArtifactId ? Artifact::find($this->leftArtifactId) : null;
        $rightArtifact = $this->rightArtifactId ? Artifact::find($this->rightArtifactId) : null;
        $baseArtifact = $this->baseArtifactId ? Artifact::find($this->baseArtifactId) : null;

        return view('livewire.releases.release-diff-page', [
            'segments' => $primarySegments,
            'strategyName' => $strategy->name(),
            'threeWayLeftSegments' => $threeWayLeftSegments,
            'threeWayRightSegments' => $threeWayRightSegments,
            'conflicts' => $conflicts,
            'leftArtifact' => $leftArtifact,
            'rightArtifact' => $rightArtifact,
            'baseArtifact' => $baseArtifact,
            'attached' => $this->release->artifacts()->orderByPivot('sort_order')->get(),
        ])->layout('layouts.app', ['header' => 'Diff: '.$this->release->name]);
    }

    /**
     * Identifies paths/lines modified on BOTH sides relative to base — these
     * are merge conflicts. For text strategy, "path" is a line number; for JSON
     * it's the JSONPath. We compare by stringified identifier.
     *
     * @param  array<int, array<string, mixed>>  $leftSegs
     * @param  array<int, array<string, mixed>>  $rightSegs
     * @return array<int, array<string, mixed>>
     */
    private function findConflicts(array $leftSegs, array $rightSegs): array
    {
        $modifyingTypes = ['add', 'remove', 'change'];

        $leftPaths = collect($leftSegs)
            ->filter(fn ($s) => in_array($s['type'] ?? '', $modifyingTypes, true))
            ->keyBy(fn ($s) => $s['path'] ?? (string) ($s['left'] ?? '').':'.(string) ($s['right'] ?? ''));

        $rightPaths = collect($rightSegs)
            ->filter(fn ($s) => in_array($s['type'] ?? '', $modifyingTypes, true))
            ->keyBy(fn ($s) => $s['path'] ?? (string) ($s['left'] ?? '').':'.(string) ($s['right'] ?? ''));

        $conflicts = [];
        foreach ($leftPaths as $key => $leftSeg) {
            if ($rightPaths->has($key)) {
                $conflicts[] = [
                    'path' => $key,
                    'left' => $leftSeg,
                    'right' => $rightPaths[$key],
                ];
            }
        }

        return $conflicts;
    }
}
