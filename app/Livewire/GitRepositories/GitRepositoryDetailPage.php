<?php

namespace App\Livewire\GitRepositories;

use App\Domain\GitRepository\Actions\TestGitConnectionAction;
use App\Domain\GitRepository\Models\GitRepository;
use Livewire\Component;

class GitRepositoryDetailPage extends Component
{
    public GitRepository $gitRepository;

    public bool $testing = false;

    public ?string $testMessage = null;

    public bool $testSuccess = false;

    public function testConnection(): void
    {
        $this->testing = true;
        $this->testMessage = null;

        $result = app(TestGitConnectionAction::class)->execute($this->gitRepository);

        $this->testSuccess = $result['success'];
        $this->testMessage = $result['message'];
        $this->testing = false;

        $this->gitRepository->refresh();
    }

    public function render()
    {
        $operations = $this->gitRepository->operations()
            ->latest('created_at')
            ->limit(20)
            ->get();

        $pullRequests = $this->gitRepository->pullRequests()
            ->where('status', 'open')
            ->latest()
            ->limit(10)
            ->get();

        return view('livewire.git-repositories.git-repository-detail-page', [
            'operations' => $operations,
            'pullRequests' => $pullRequests,
        ])->layout('layouts.app', ['header' => $this->gitRepository->name]);
    }
}
