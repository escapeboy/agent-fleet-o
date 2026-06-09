<?php

namespace App\Livewire\Credentials;

use App\Domain\Agent\Models\Agent;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Credential\Jobs\CredentialScanJob;
use App\Domain\Skill\Models\Skill;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Surfaces secret-scan findings produced by SecretScanObserver / CredentialScanJob.
 *
 * Findings are persisted as AuditEntry rows (event = 'secret_detected') with the
 * matched pattern stored in the `properties` JSONB column. This page lists those
 * findings team-scoped, lets a user re-scan the originating model, and acknowledge
 * (dismiss) a finding.
 */
class CredentialScanPage extends Component
{
    use WithPagination;

    /** Map of scannable subject types -> their model class + scannable fields. */
    private const SUBJECT_MAP = [
        'agent' => [Agent::class, ['role', 'goal', 'backstory']],
        'skill' => [Skill::class, ['description', 'system_prompt']],
        'workflow_node' => [WorkflowNode::class, ['label', 'expression']],
    ];

    #[Url]
    public string $subjectTypeFilter = '';

    #[Url]
    public bool $showAcknowledged = false;

    public function updatedSubjectTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedShowAcknowledged(): void
    {
        $this->resetPage();
    }

    /**
     * Re-run the secret scanner against the model that produced a finding.
     */
    public function rescan(string $auditEntryId): void
    {
        Gate::authorize('edit-content');

        $entry = AuditEntry::where('event', 'secret_detected')->findOrFail($auditEntryId);

        [$model, $fields] = $this->resolveSubject($entry->subject_type, $entry->subject_id);

        if ($model === null) {
            $this->dispatch('scan-rescan-missing');

            return;
        }

        $textFields = [];
        foreach ($fields as $field) {
            $value = $model->getAttribute($field);
            if (is_string($value) && $value !== '') {
                $textFields[$field] = $value;
            }
        }

        if ($textFields === []) {
            $this->dispatch('scan-rescan-empty');

            return;
        }

        $contentHash = sha1(implode('|', $textFields));

        // Clear the 24h dedup key so the job actually re-evaluates and re-records.
        Cache::forget("secret_scan:{$entry->subject_type}:{$entry->subject_id}:{$contentHash}");

        CredentialScanJob::dispatch(
            $entry->team_id,
            $entry->subject_type,
            $entry->subject_id,
            $textFields,
            $contentHash,
        );

        $this->dispatch('scan-rescan-queued');
    }

    /**
     * Acknowledge (dismiss) a finding by stamping its properties and removing it from the active view.
     */
    public function acknowledge(string $auditEntryId): void
    {
        Gate::authorize('edit-content');

        $entry = AuditEntry::where('event', 'secret_detected')->findOrFail($auditEntryId);

        $properties = $entry->properties ?? [];
        $properties['acknowledged_at'] = now()->toIso8601String();
        $properties['acknowledged_by'] = auth()->id();

        $entry->properties = $properties;
        $entry->save();

        $this->dispatch('scan-finding-acknowledged');
    }

    /**
     * Resolve the originating model and its scannable fields for a finding.
     *
     * @return array{0: Model|null, 1: array<int, string>}
     */
    private function resolveSubject(string $subjectType, string $subjectId): array
    {
        $config = self::SUBJECT_MAP[$subjectType]
            ?? collect(self::SUBJECT_MAP)->first(
                fn (array $c) => $c[0] === $subjectType,
            );

        if ($config === null) {
            return [null, []];
        }

        [$class, $fields] = $config;

        return [$class::find($subjectId), $fields];
    }

    public function render()
    {
        $query = AuditEntry::query()->where('event', 'secret_detected');

        if ($this->subjectTypeFilter !== '') {
            $query->where('subject_type', $this->subjectTypeFilter);
        }

        if (! $this->showAcknowledged) {
            $query->whereNull('properties->acknowledged_at');
        }

        $findings = $query->orderByDesc('created_at')->paginate(25);

        $openCount = AuditEntry::query()
            ->where('event', 'secret_detected')
            ->whereNull('properties->acknowledged_at')
            ->count();

        return view('livewire.credentials.credential-scan-page', [
            'findings' => $findings,
            'openCount' => $openCount,
            'subjectTypes' => array_keys(self::SUBJECT_MAP),
        ])->layout('layouts.app', ['header' => 'Secret Scan Findings']);
    }
}
