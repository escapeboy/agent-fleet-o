<?php

namespace App\Livewire\Settings;

use App\Models\GlobalSetting;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class SecurityPolicyPanel extends Component
{
    public string $blockedCommands = '';

    public string $blockedPatterns = '';

    public string $allowedCommands = '';

    public string $allowedPaths = '';

    public string $requireApprovalFor = '';

    public ?int $maxCommandTimeout = null;

    public bool $editing = false;

    public function mount(): void
    {
        $this->loadPolicy();
    }

    private function loadPolicy(): void
    {
        $policy = GlobalSetting::get('org_security_policy', []);

        $this->blockedCommands = implode("\n", $policy['blocked_commands'] ?? []);
        $this->blockedPatterns = implode("\n", $policy['blocked_patterns'] ?? []);
        $this->allowedCommands = implode("\n", $policy['allowed_commands'] ?? []);
        $this->allowedPaths = implode("\n", $policy['allowed_paths'] ?? []);
        $this->requireApprovalFor = implode("\n", $policy['require_approval_for'] ?? []);
        $this->maxCommandTimeout = $policy['max_command_timeout'] ?? null;
    }

    public function save(): void
    {
        Gate::authorize('feature.security_policy');

        $policy = [
            'blocked_commands' => $this->parseLines($this->blockedCommands),
            'blocked_patterns' => $this->parseLines($this->blockedPatterns),
            'allowed_commands' => $this->parseLines($this->allowedCommands),
            'allowed_paths' => $this->parseLines($this->allowedPaths),
            'require_approval_for' => $this->parseLines($this->requireApprovalFor),
            'max_command_timeout' => $this->maxCommandTimeout,
        ];

        // Remove empty arrays to keep it clean
        $policy = array_filter($policy, fn ($v) => ! empty($v));

        GlobalSetting::set('org_security_policy', $policy);

        $this->editing = false;
        session()->flash('security-saved', 'Organization security policy updated.');
    }

    public function resetPolicy(): void
    {
        Gate::authorize('feature.security_policy');

        GlobalSetting::set('org_security_policy', []);
        $this->loadPolicy();
        $this->editing = false;
        session()->flash('security-saved', 'Organization security policy reset to defaults.');
    }

    private function parseLines(string $text): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n", $text)),
            fn ($line) => $line !== '',
        ));
    }

    public static function getOrgPolicy(): array
    {
        return GlobalSetting::get('org_security_policy', []);
    }

    public function render()
    {
        return view('livewire.settings.security-policy-panel');
    }
}
