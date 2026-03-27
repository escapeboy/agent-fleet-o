<?php

namespace App\Livewire\Telegram;

use App\Domain\Project\Models\Project;
use App\Domain\Telegram\Actions\RegisterTelegramBotAction;
use App\Domain\Telegram\Models\TelegramBot;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class TelegramBotsPage extends Component
{
    public bool $showForm = false;

    public string $botToken = '';

    public string $routingMode = 'assistant';

    public string $defaultProjectId = '';

    public ?string $error = null;

    public ?string $success = null;

    public bool $saving = false;

    public function rules(): array
    {
        return [
            'botToken' => ['required', 'string', 'min:10'],
            'routingMode' => ['required', 'in:assistant,project,trigger_rules'],
            'defaultProjectId' => [$this->routingMode === 'project' ? 'required' : 'nullable', 'nullable', 'exists:projects,id'],
        ];
    }

    public function save(): void
    {
        $this->validate();
        $this->error = null;

        try {
            $teamId = auth()->user()->current_team_id;

            app(RegisterTelegramBotAction::class)->execute(
                teamId: $teamId,
                botToken: $this->botToken,
                routingMode: $this->routingMode,
                defaultProjectId: $this->defaultProjectId ?: null,
            );

            $this->botToken = '';
            $this->showForm = false;
            $this->success = 'Telegram bot registered successfully.';
        } catch (ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function toggleStatus(string $botId): void
    {
        $bot = TelegramBot::findOrFail($botId);
        $bot->update(['status' => $bot->isActive() ? 'disabled' : 'active']);
        $this->success = 'Bot status updated.';
        $this->error = null;
    }

    public function delete(string $botId): void
    {
        TelegramBot::findOrFail($botId)->delete();
        $this->success = 'Bot removed.';
        $this->error = null;
    }

    public function openForm(): void
    {
        $this->showForm = true;
        $this->reset(['botToken', 'routingMode', 'defaultProjectId', 'error', 'success']);
        $this->routingMode = 'assistant';
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->reset(['botToken', 'routingMode', 'defaultProjectId', 'error']);
    }

    public function render()
    {
        $bots = TelegramBot::with(['defaultProject', 'chatBindings'])->get();
        $projects = Project::orderBy('title')->get(['id', 'title']);

        return view('livewire.telegram.telegram-bots-page', [
            'bots' => $bots,
            'projects' => $projects,
        ])->layout('layouts.app', ['header' => 'Telegram Bots']);
    }
}
