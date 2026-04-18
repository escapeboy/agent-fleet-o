<?php

namespace App\Livewire\Signals;

use App\Domain\Signal\Actions\StructureSignalWithAiAction;
use App\Domain\Signal\Connectors\ManualSignalConnector;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Component;

class ManualSignalForm extends Component
{
    public string $rawText = '';

    public string $title = '';

    public string $description = '';

    public string $priority = 'medium';

    public string $sourceType = 'manual';

    /** @var string[] */
    public array $tags = [];

    public string $tagsInput = '';

    /** @var array<string, mixed> */
    public array $metadata = [];

    public bool $isStructuring = false;

    public bool $isStructured = false;

    public bool $submitted = false;

    public function structureWithAi(): void
    {
        $this->validate(['rawText' => 'required|min:5|max:4000']);

        $userId = auth()->id();
        $rateLimitKey = "signal-ai:{$userId}";

        if (RateLimiter::tooManyAttempts($rateLimitKey, 10)) {
            $this->addError('rawText', 'Too many AI requests. Please wait a moment before trying again.');

            return;
        }

        RateLimiter::hit($rateLimitKey, 60);

        $this->isStructuring = true;

        $teamId = auth()->user()?->currentTeam?->id;

        if (! $teamId) {
            $this->addError('rawText', 'No active team found.');
            $this->isStructuring = false;

            return;
        }

        $structured = app(StructureSignalWithAiAction::class)->execute($this->rawText, $teamId);

        $this->title = $structured['title'];
        $this->description = $structured['description'];
        $this->priority = $structured['priority'];
        $this->tags = $structured['tags'];
        $this->tagsInput = implode(', ', $structured['tags']);
        $this->sourceType = $structured['source_type'];
        $this->metadata = $structured['metadata'];
        $this->isStructuring = false;
        $this->isStructured = true;
    }

    public function submit(): void
    {
        $this->validate([
            'title' => 'required|min:3|max:80',
            'description' => 'required|min:5',
            'priority' => 'required|in:low,medium,high,critical',
            'sourceType' => 'required|string',
        ]);

        $user = auth()->user();
        $teamId = $user?->currentTeam?->id;

        if (! $teamId) {
            $this->addError('title', 'No active team found.');

            return;
        }

        // Parse tags from comma-separated input if modified
        $tags = array_filter(array_map('trim', explode(',', $this->tagsInput)));

        $payload = array_filter([
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
        ]);

        if (! empty($this->metadata)) {
            $payload['metadata'] = $this->metadata;
        }

        app(ManualSignalConnector::class)->ingest(
            userId: $user->id,
            title: $this->title,
            thesis: $this->description,
            track: $this->sourceType,
            payload: $payload,
            autoStart: false,
        );

        $this->submitted = true;
        session()->flash('signal_created', 'Signal created successfully.');
        $this->redirectRoute('signals.index');
    }

    public function render(): View
    {
        return view('livewire.signals.manual-signal-form')
            ->layout('layouts.app', ['header' => 'New Signal']);
    }
}
