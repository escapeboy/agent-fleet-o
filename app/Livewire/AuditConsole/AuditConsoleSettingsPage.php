<?php

namespace App\Livewire\AuditConsole;

use FleetQ\BorunaAudit\Services\QuotaEnforcer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class AuditConsoleSettingsPage extends Component
{
    public bool $enabled = true;

    public bool $shadowMode = true;

    public array $workflowsEnabled = [];

    public int $retentionDays = 90;

    public ?int $quotaPerMonth = null;

    public function mount(): void
    {
        abort_unless(Gate::check('manage-team'), 403);

        $teamId = auth()->user()->currentTeam->id;
        $setting = DB::table('boruna_tenant_settings')->where('team_id', $teamId)->first();

        if ($setting) {
            $this->enabled = (bool) $setting->enabled;
            $this->shadowMode = (bool) $setting->shadow_mode;
            $this->workflowsEnabled = json_decode($setting->workflows_enabled ?? '{}', true) ?? [];
            $this->retentionDays = (int) $setting->retention_days;
            $this->quotaPerMonth = $setting->quota_per_month ? (int) $setting->quota_per_month : null;
        } else {
            $this->workflowsEnabled = array_fill_keys(
                array_keys(config('boruna_audit.workflows', [])),
                true,
            );
        }
    }

    public function save(): void
    {
        abort_unless(Gate::check('manage-team'), 403);

        $this->validate([
            'retentionDays' => 'required|integer|min:1|max:3650',
            'quotaPerMonth' => 'nullable|integer|min:1',
        ]);

        $teamId = auth()->user()->currentTeam->id;

        $exists = DB::table('boruna_tenant_settings')->where('team_id', $teamId)->exists();

        if ($exists) {
            DB::table('boruna_tenant_settings')->where('team_id', $teamId)->update([
                'enabled' => $this->enabled,
                'shadow_mode' => $this->shadowMode,
                'workflows_enabled' => json_encode($this->workflowsEnabled),
                'retention_days' => $this->retentionDays,
                'quota_per_month' => $this->quotaPerMonth,
                'updated_at' => now(),
            ]);
        } else {
            DB::table('boruna_tenant_settings')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'team_id' => $teamId,
                'enabled' => $this->enabled,
                'shadow_mode' => $this->shadowMode,
                'workflows_enabled' => json_encode($this->workflowsEnabled),
                'retention_days' => $this->retentionDays,
                'quota_per_month' => $this->quotaPerMonth,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        session()->flash('success', 'Audit console settings saved.');
    }

    public function render()
    {
        $teamId = auth()->user()->currentTeam->id;
        $quota = app(QuotaEnforcer::class)->usage($teamId);

        $bundleStorageBytes = 0;
        $disk = Storage::disk(config('boruna_audit.storage_disk', 'boruna_bundles'));

        try {
            $files = $disk->allFiles($teamId);
            foreach ($files as $file) {
                $bundleStorageBytes += $disk->size($file);
            }
        } catch (\Throwable) {
            // Disk may not exist yet
        }

        $workflows = array_keys(config('boruna_audit.workflows', []));

        return view('livewire.audit-console.settings', compact('quota', 'bundleStorageBytes', 'workflows'))
            ->title('Audit Console Settings');
    }
}
