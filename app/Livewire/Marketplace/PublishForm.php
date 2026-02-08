<?php

namespace App\Livewire\Marketplace;

use App\Domain\Agent\Models\Agent;
use App\Domain\Marketplace\Actions\PublishToMarketplaceAction;
use App\Domain\Marketplace\Enums\ListingVisibility;
use App\Domain\Skill\Models\Skill;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use Livewire\Component;

class PublishForm extends Component
{
    public string $itemType = 'skill';
    public string $itemId = '';
    public string $name = '';
    public string $description = '';
    public string $readme = '';
    public string $category = '';
    public string $tagsInput = '';
    public string $visibility = 'public';

    protected function rules(): array
    {
        return [
            'itemType' => 'required|in:skill,agent,workflow',
            'itemId' => 'required|uuid',
            'name' => 'required|string|min:3|max:100',
            'description' => 'required|string|min:10|max:500',
            'readme' => 'nullable|string|max:10000',
            'category' => 'nullable|string|max:50',
            'tagsInput' => 'nullable|string|max:200',
            'visibility' => 'required|in:public,unlisted',
        ];
    }

    public function updatedItemId(): void
    {
        if (! $this->itemId) {
            return;
        }

        $item = match ($this->itemType) {
            'skill' => Skill::find($this->itemId),
            'agent' => Agent::find($this->itemId),
            'workflow' => Workflow::find($this->itemId),
            default => null,
        };

        if ($item) {
            $this->name = $item->name;
            $this->description = $item->description ?? '';
        }
    }

    public function publish(): void
    {
        $this->validate();

        $user = auth()->user();
        $team = $user->currentTeam;

        if (! $team) {
            session()->flash('error', 'You must belong to a team to publish.');
            return;
        }

        $item = match ($this->itemType) {
            'skill' => Skill::findOrFail($this->itemId),
            'agent' => Agent::findOrFail($this->itemId),
            'workflow' => Workflow::findOrFail($this->itemId),
        };

        $tags = $this->tagsInput
            ? array_map('trim', explode(',', $this->tagsInput))
            : [];

        $listing = app(PublishToMarketplaceAction::class)->execute(
            item: $item,
            teamId: $team->id,
            userId: $user->id,
            name: $this->name,
            description: $this->description,
            readme: $this->readme ?: null,
            category: $this->category ?: null,
            tags: $tags,
            visibility: ListingVisibility::from($this->visibility),
        );

        session()->flash('success', "{$this->name} published to marketplace!");
        $this->redirect(route('marketplace.show', $listing), navigate: true);
    }

    public function render()
    {
        $skills = Skill::where('status', 'active')->get(['id', 'name']);
        $agents = Agent::where('status', 'active')->get(['id', 'name']);
        $workflows = Workflow::where('status', WorkflowStatus::Active)->get(['id', 'name']);

        return view('livewire.marketplace.publish-form', [
            'skills' => $skills,
            'agents' => $agents,
            'workflows' => $workflows,
            'canPublish' => true,
        ])->layout('layouts.app', ['header' => 'Publish to Marketplace']);
    }
}
