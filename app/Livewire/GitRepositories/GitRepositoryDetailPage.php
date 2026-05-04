<?php

namespace App\Livewire\GitRepositories;

use App\Domain\GitRepository\Actions\TestGitConnectionAction;
use App\Domain\GitRepository\Enums\TestRatchetMode;
use App\Domain\GitRepository\Models\GitRepository;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class GitRepositoryDetailPage extends Component
{
    public GitRepository $gitRepository;

    public bool $testing = false;

    public ?string $testMessage = null;

    public bool $testSuccess = false;

    public string $testRatchetMode = '';

    public ?string $testRatchetSavedMessage = null;

    public function mount(): void
    {
        $config = $this->gitRepository->config ?? [];
        $raw = (string) ($config['test_ratchet_mode'] ?? TestRatchetMode::Soft->value);
        $this->testRatchetMode = (TestRatchetMode::tryFrom($raw) ?? TestRatchetMode::Soft)->value;
    }

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

    public function saveTestRatchetMode(): void
    {
        Gate::authorize('edit-content');

        $this->validate([
            'testRatchetMode' => 'required|in:'.implode(',', array_column(TestRatchetMode::cases(), 'value')),
        ]);

        $config = $this->gitRepository->config ?? [];
        $config['test_ratchet_mode'] = $this->testRatchetMode;
        $this->gitRepository->update(['config' => $config]);
        $this->gitRepository->refresh();

        $mode = TestRatchetMode::from($this->testRatchetMode);
        $this->testRatchetSavedMessage = "Test ratchet mode set to {$mode->label()}.";
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
            'testRatchetModes' => TestRatchetMode::cases(),
        ])->layout('layouts.app', ['header' => $this->gitRepository->name]);
    }
}
