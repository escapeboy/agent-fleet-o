<?php

declare(strict_types=1);

namespace App\Livewire\Releases;

use App\Domain\Release\Actions\ArchiveReleaseAction;
use App\Domain\Release\Actions\AttachArtifactAction;
use App\Domain\Release\Actions\PublishReleaseAction;
use App\Domain\Release\Models\Release;
use App\Models\Artifact;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Component;

class ReleaseDetailPage extends Component
{
    public Release $release;

    public string $attachArtifactId = '';

    public function mount(Release $release): void
    {
        $this->release = $release;
    }

    public function publish(PublishReleaseAction $action): void
    {
        Gate::authorize('edit-content');

        try {
            $action->execute($this->release);
        } catch (InvalidArgumentException $e) {
            $this->addError('release', $e->getMessage());

            return;
        }

        $this->release->refresh();
        session()->flash('message', 'Release published.');
    }

    public function archive(ArchiveReleaseAction $action): void
    {
        Gate::authorize('edit-content');

        $action->execute($this->release);
        $this->release->refresh();
        session()->flash('message', 'Release archived.');
    }

    public function attach(AttachArtifactAction $action): void
    {
        Gate::authorize('edit-content');

        if ($this->attachArtifactId === '') {
            return;
        }

        $artifact = Artifact::find($this->attachArtifactId);
        if (! $artifact) {
            $this->addError('attachArtifactId', 'Artifact not found.');

            return;
        }

        try {
            $action->execute($this->release, $artifact);
        } catch (InvalidArgumentException $e) {
            $this->addError('attachArtifactId', $e->getMessage());

            return;
        }

        $this->attachArtifactId = '';
        $this->release->load('artifacts');
        session()->flash('message', 'Artifact attached.');
    }

    public function render()
    {
        $artifacts = $this->release->artifacts()->orderByPivot('sort_order')->get();
        $attachedIds = $this->release->releaseArtifacts()->pluck('artifact_id')->all();
        $availableArtifacts = Artifact::where('team_id', $this->release->team_id)
            ->whereNotIn('id', $attachedIds)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('livewire.releases.release-detail-page', [
            'artifacts' => $artifacts,
            'availableArtifacts' => $availableArtifacts,
        ])->layout('layouts.app', ['header' => $this->release->name.' '.$this->release->version]);
    }
}
