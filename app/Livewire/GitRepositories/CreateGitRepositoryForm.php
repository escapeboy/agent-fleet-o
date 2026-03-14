<?php

namespace App\Livewire\GitRepositories;

use App\Domain\Credential\Models\Credential;
use App\Domain\GitRepository\Actions\CreateGitRepositoryAction;
use App\Domain\GitRepository\Enums\GitProvider;
use App\Domain\GitRepository\Enums\GitRepoMode;
use Livewire\Component;

class CreateGitRepositoryForm extends Component
{
    public int $step = 1;

    // Step 1: Basics
    public string $name = '';

    public string $url = '';

    public string $provider = 'github';

    public string $defaultBranch = 'main';

    // Step 2: Mode
    public string $mode = 'api_only';

    // Step 3: Credential & config
    public string $credentialId = '';

    // Sandbox config
    public string $sandboxProvider = 'runpod';

    public string $sandboxInstanceType = 'CPU';

    public bool $runTests = false;

    public string $testCommand = '';

    // Bridge config
    public string $bridgeRepoName = '';

    public string $bridgeWorkingDirectory = '';

    // PR config
    public bool $requireApproval = false;

    public function updatedUrl(): void
    {
        if ($this->url) {
            $this->provider = GitProvider::detectFromUrl($this->url)->value;
            if (! $this->name) {
                // Auto-fill name from URL
                if (preg_match('#/([^/]+?)(?:\.git)?$#', $this->url, $m)) {
                    $this->name = $m[1];
                }
            }
        }
    }

    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->validate([
                'name' => 'required|min:2|max:255',
                'url' => 'required|url|max:2048',
                'provider' => 'required|in:github,gitlab,bitbucket,gitea,generic',
                'defaultBranch' => 'required|max:255',
            ]);
        }

        $this->step = min(3, $this->step + 1);
    }

    public function prevStep(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|min:2|max:255',
            'url' => 'required|max:2048',
            'mode' => 'required|in:api_only,sandbox,bridge',
            'provider' => 'required|in:github,gitlab,bitbucket,gitea,generic',
            'defaultBranch' => 'required|max:255',
            'credentialId' => 'nullable|uuid|exists:credentials,id',
        ]);

        $config = ['pr' => ['require_approval' => $this->requireApproval]];

        if ($this->mode === 'sandbox') {
            $config['sandbox'] = array_filter([
                'provider' => $this->sandboxProvider,
                'instance_type' => $this->sandboxInstanceType,
                'run_tests' => $this->runTests,
                'test_command' => $this->testCommand ?: null,
            ]);
        }

        if ($this->mode === 'bridge') {
            $config['bridge'] = array_filter([
                'repo_name' => $this->bridgeRepoName ?: $this->name,
                'working_directory' => $this->bridgeWorkingDirectory ?: null,
            ]);
        }

        $repo = app(CreateGitRepositoryAction::class)->execute(
            teamId: auth()->user()->currentTeam->id,
            name: $this->name,
            url: $this->url,
            mode: GitRepoMode::from($this->mode),
            provider: GitProvider::from($this->provider),
            defaultBranch: $this->defaultBranch,
            credentialId: $this->credentialId ?: null,
            config: $config,
        );

        session()->flash('message', 'Git repository connected successfully.');
        $this->redirect(route('git-repositories.show', $repo));
    }

    public function render()
    {
        $credentials = Credential::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'credential_type']);

        return view('livewire.git-repositories.create-git-repository-form', [
            'modes' => GitRepoMode::cases(),
            'providers' => GitProvider::cases(),
            'credentials' => $credentials,
        ])->layout('layouts.app', ['header' => 'Connect Git Repository']);
    }
}
