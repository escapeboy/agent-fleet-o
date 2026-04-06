<?php

namespace App\Livewire\Agents;

use App\Domain\Agent\Actions\CreateAgentAction;
use App\Domain\Project\Actions\CreateProjectAction;
use App\Infrastructure\AI\Services\ProviderResolver;
use Livewire\Component;

class QuickAgentForm extends Component
{
    public string $markdown = '';

    public string $name = '';

    public string $schedule = 'daily';

    public string $provider = '';

    public string $model = '';

    public bool $createProject = true;

    public function mount(): void
    {
        $resolved = app(ProviderResolver::class)->resolve(team: auth()->user()?->currentTeam);
        $this->provider = $resolved['provider'];
        $this->model = $resolved['model'];
    }

    protected function rules(): array
    {
        return [
            'name' => 'required|min:2|max:255',
            'markdown' => 'required|min:10|max:10000',
            'schedule' => 'required|in:hourly,daily,weekly,monthly,manual',
            'provider' => 'required|in:anthropic,openai,google',
            'model' => 'required|max:255',
        ];
    }

    public function save(): void
    {
        $this->validate();

        $team = auth()->user()->currentTeam;

        $parsed = $this->parseMarkdown($this->markdown);

        $agent = app(CreateAgentAction::class)->execute(
            name: $this->name,
            provider: $this->provider,
            model: $this->model,
            teamId: $team->id,
            role: $parsed['role'] ?? 'Autonomous Agent',
            goal: $parsed['goal'] ?? mb_substr($this->markdown, 0, 1000),
            backstory: $parsed['backstory'],
            personality: $parsed['personality'],
        );

        // Optionally create a scheduled project for this agent
        if ($this->createProject && $this->schedule !== 'manual') {
            app(CreateProjectAction::class)->execute(
                userId: auth()->id(),
                title: "{$this->name} — Scheduled Run",
                type: 'continuous',
                goal: $parsed['goal'] ?? mb_substr($this->markdown, 0, 500),
                agentConfig: ['agent_id' => $agent->id],
                teamId: $team->id,
                schedule: [
                    'frequency' => $this->schedule,
                    'timezone' => config('app.timezone', 'UTC'),
                    'overlap_policy' => 'skip',
                    'enabled' => true,
                ],
            );
        }

        session()->flash('message', 'Quick agent created!'.($this->createProject && $this->schedule !== 'manual' ? ' Scheduled project created too.' : ''));

        $this->redirect(route('agents.show', $agent));
    }

    /**
     * Parse markdown prompt into structured agent fields.
     *
     * Supports optional YAML-like frontmatter sections:
     *   role: ...
     *   goal: ...
     *
     * The body becomes the backstory / system prompt.
     */
    private function parseMarkdown(string $markdown): array
    {
        $result = [
            'role' => null,
            'goal' => null,
            'backstory' => null,
            'personality' => null,
        ];

        $lines = explode("\n", $markdown);
        $body = [];
        $inFrontmatter = false;
        $frontmatter = [];

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);

            // Detect YAML frontmatter block
            if ($i === 0 && $trimmed === '---') {
                $inFrontmatter = true;

                continue;
            }

            if ($inFrontmatter) {
                if ($trimmed === '---') {
                    $inFrontmatter = false;

                    continue;
                }
                if (preg_match('/^(\w+)\s*:\s*(.+)$/', $trimmed, $m)) {
                    $frontmatter[strtolower($m[1])] = trim($m[2]);
                }

                continue;
            }

            $body[] = $line;
        }

        $result['role'] = $frontmatter['role'] ?? null;
        $result['goal'] = $frontmatter['goal'] ?? null;

        $bodyText = trim(implode("\n", $body));
        if ($bodyText !== '') {
            $result['backstory'] = $bodyText;
        }

        // Extract personality hints from frontmatter
        $personality = array_filter([
            'tone' => $frontmatter['tone'] ?? null,
            'communication_style' => $frontmatter['style'] ?? null,
        ]);
        $result['personality'] = ! empty($personality) ? $personality : null;

        return $result;
    }

    public function render()
    {
        return view('livewire.agents.quick-agent-form')
            ->layout('layouts.app', ['header' => 'Quick Agent']);
    }
}
