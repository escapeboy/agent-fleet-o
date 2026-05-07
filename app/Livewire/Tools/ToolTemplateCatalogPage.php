<?php

namespace App\Livewire\Tools;

use App\Domain\Tool\Actions\DeployToolTemplateAction;
use App\Domain\Tool\Enums\ToolTemplateCategory;
use App\Domain\Tool\Models\ToolTemplate;
use App\Infrastructure\Compute\Services\ComputeCredentialResolver;
use Livewire\Component;

class ToolTemplateCatalogPage extends Component
{
    public string $category = '';

    public string $search = '';

    public bool $showDeployModal = false;

    public ?string $selectedTemplateId = null;

    public string $deployProvider = '';

    public string $deployEndpointId = '';

    public function deploy(): void
    {
        $template = ToolTemplate::find($this->selectedTemplateId);

        if (! $template) {
            session()->flash('error', 'Template not found.');

            return;
        }

        $team = auth()->user()->currentTeam;
        $provider = $this->deployProvider ?: $template->provider;

        // Check if team has compute credentials for this provider
        $credentialResolver = app(ComputeCredentialResolver::class);
        $credentials = $credentialResolver->resolve($team->id, $provider);

        if (! $credentials && ! $this->deployEndpointId) {
            session()->flash('error', "No {$provider} credentials configured. Add your API key in Team Settings > Provider Credentials, or provide an existing endpoint ID.");

            return;
        }

        $endpointId = $this->deployEndpointId ?: null;

        $tool = app(DeployToolTemplateAction::class)->execute(
            teamId: $team->id,
            template: $template,
            provider: $provider,
            endpointId: $endpointId,
        );

        $this->showDeployModal = false;
        $this->selectedTemplateId = null;
        $this->deployProvider = '';
        $this->deployEndpointId = '';

        session()->flash('message', "Tool '{$tool->name}' created from template. ".($endpointId ? 'Connected to existing endpoint.' : 'Configure the endpoint ID in tool settings to activate.'));

        $this->redirect(route('tools.show', $tool));
    }

    public function openDeployModal(string $templateId): void
    {
        $this->selectedTemplateId = $templateId;
        $template = ToolTemplate::find($templateId);
        $this->deployProvider = $template->provider ?? 'runpod';
        $this->deployEndpointId = '';
        $this->showDeployModal = true;
    }

    public function closeDeployModal(): void
    {
        $this->showDeployModal = false;
        $this->selectedTemplateId = null;
    }

    public function render()
    {
        $query = ToolTemplate::active()->orderBy('sort_order');

        if ($this->category) {
            $query->where('category', $this->category);
        }

        if ($this->search) {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $this->search);
            $query->where(function ($q) use ($escaped) {
                $q->where('name', 'ilike', "%{$escaped}%")
                    ->orWhere('description', 'ilike', "%{$escaped}%");
            });
        }

        $templates = $query->get();
        $categories = ToolTemplateCategory::cases();
        $selectedTemplate = $this->selectedTemplateId ? ToolTemplate::find($this->selectedTemplateId) : null;

        // Check which providers have credentials
        $team = auth()->user()->currentTeam;
        $credentialResolver = app(ComputeCredentialResolver::class);
        $providerStatus = [];
        foreach (config('compute_providers.providers', []) as $slug => $info) {
            $providerStatus[$slug] = [
                'label' => $info['label'],
                'configured' => $credentialResolver->resolve($team->id, $slug) !== null,
            ];
        }

        return view('livewire.tools.tool-template-catalog-page', [
            'templates' => $templates,
            'categories' => $categories,
            'selectedTemplate' => $selectedTemplate,
            'providerStatus' => $providerStatus,
        ])->layout('layouts.app', ['header' => 'GPU Tool Templates']);
    }
}
