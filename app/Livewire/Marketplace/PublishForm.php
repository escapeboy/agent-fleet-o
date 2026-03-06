<?php

namespace App\Livewire\Marketplace;

use App\Domain\Agent\Models\Agent;
use App\Domain\Email\Models\EmailTemplate;
use App\Domain\Email\Models\EmailTheme;
use App\Domain\Marketplace\Actions\PublishBundleAction;
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

    /** @var array<array{type: string, id: string}> Items in a bundle */
    public array $bundleItems = [];

    public string $bundleAddType = 'skill';

    public string $bundleAddId = '';

    public string $name = '';

    public string $description = '';

    public string $readme = '';

    public string $category = '';

    public string $tagsInput = '';

    public string $visibility = 'public';

    public bool $monetizationEnabled = false;

    public string $pricePerRun = '0';

    public array $requiredProviders = [];

    public function addBundleItemFromState(): void
    {
        $type = $this->bundleAddType;
        $id = $this->bundleAddId;

        if (! $id || ! in_array($type, ['skill', 'agent', 'workflow', 'email_theme', 'email_template'])) {
            return;
        }
        foreach ($this->bundleItems as $existing) {
            if ($existing['type'] === $type && $existing['id'] === $id) {
                return;
            }
        }
        $this->bundleItems[] = ['type' => $type, 'id' => $id];
        $this->bundleAddId = '';
    }

    public function removeBundleItem(int $index): void
    {
        array_splice($this->bundleItems, $index, 1);
    }

    protected function rules(): array
    {
        if ($this->itemType === 'bundle') {
            return [
                'itemType' => 'required|in:skill,agent,workflow,email_theme,email_template,bundle',
                'bundleItems' => 'required|array|min:2',
                'bundleItems.*.type' => 'required|in:skill,agent,workflow',
                'bundleItems.*.id' => 'required|uuid',
                'name' => 'required|string|min:3|max:100',
                'description' => 'required|string|min:10|max:500',
                'readme' => 'nullable|string|max:10000',
                'category' => 'nullable|string|max:50',
                'tagsInput' => 'nullable|string|max:200',
                'visibility' => 'required|in:public,unlisted,team',
            ];
        }

        return [
            'itemType' => 'required|in:skill,agent,workflow,email_theme,email_template,bundle',
            'itemId' => 'required|uuid',
            'name' => 'required|string|min:3|max:100',
            'description' => 'required|string|min:10|max:500',
            'readme' => 'nullable|string|max:10000',
            'category' => 'nullable|string|max:50',
            'tagsInput' => 'nullable|string|max:200',
            'visibility' => 'required|in:public,unlisted,team',
            'monetizationEnabled' => 'boolean',
            'pricePerRun' => 'required_if:monetizationEnabled,true|numeric|min:0|max:10000',
            'requiredProviders' => 'array',
            'requiredProviders.*' => 'in:anthropic,openai,google',
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
            'email_theme' => EmailTheme::find($this->itemId),
            'email_template' => EmailTemplate::find($this->itemId),
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

        $tags = $this->tagsInput
            ? array_map('trim', explode(',', $this->tagsInput))
            : [];

        if ($this->itemType === 'bundle') {
            $listing = app(PublishBundleAction::class)->execute(
                teamId: $team->id,
                userId: $user->id,
                name: $this->name,
                description: $this->description,
                items: $this->bundleItems,
                readme: $this->readme ?: null,
                category: $this->category ?: null,
                tags: $tags,
                visibility: ListingVisibility::from($this->visibility),
            );
        } else {
            $item = match ($this->itemType) {
                'skill' => Skill::findOrFail($this->itemId),
                'agent' => Agent::findOrFail($this->itemId),
                'workflow' => Workflow::findOrFail($this->itemId),
                'email_theme' => EmailTheme::findOrFail($this->itemId),
                'email_template' => EmailTemplate::findOrFail($this->itemId),
            };

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

            // Apply monetization settings
            $listing->update([
                'monetization_enabled' => $this->monetizationEnabled,
                'price_per_run_credits' => $this->monetizationEnabled ? (float) $this->pricePerRun : 0,
            ]);

            // Apply provider requirements to the skill
            if ($this->itemType === 'skill' && ! empty($this->requiredProviders)) {
                $item->update([
                    'provider_requirements' => ['required_providers' => $this->requiredProviders],
                ]);
            }
        }

        session()->flash('success', "{$this->name} published to marketplace!");
        $this->redirect(route('app.marketplace.show', $listing), navigate: true);
    }

    public function render()
    {
        $skills = Skill::where('status', 'active')->get(['id', 'name']);
        $agents = Agent::where('status', 'active')->get(['id', 'name']);
        $workflows = Workflow::where('status', WorkflowStatus::Active)->get(['id', 'name']);
        $emailThemes = EmailTheme::where('status', 'active')->orderBy('name')->get(['id', 'name']);
        $emailTemplates = EmailTemplate::where('status', 'active')->orderBy('name')->get(['id', 'name']);

        return view('livewire.marketplace.publish-form', [
            'skills' => $skills,
            'agents' => $agents,
            'workflows' => $workflows,
            'emailThemes' => $emailThemes,
            'emailTemplates' => $emailTemplates,
            'canPublish' => true,
        ])->layout('layouts.app', ['header' => 'Publish to Marketplace']);
    }
}
