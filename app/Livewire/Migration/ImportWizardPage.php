<?php

namespace App\Livewire\Migration;

use App\Domain\Migration\Actions\DetectSchemaAction;
use App\Domain\Migration\Actions\ExecuteMigrationAction;
use App\Domain\Migration\Enums\MigrationEntityType;
use App\Domain\Migration\Enums\MigrationSource;
use App\Domain\Migration\Models\MigrationRun;
use App\Domain\Migration\Services\Importers\ImporterRegistry;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class ImportWizardPage extends Component
{
    use WithFileUploads;

    /** Wizard step: 1 = upload, 2 = mapping, 3 = result. */
    public int $step = 1;

    /** @var TemporaryUploadedFile|null */
    public $file;

    public string $entityType = 'contact';

    public ?string $runId = null;

    /** @var array<string, ?string> column header → confirmed target attribute (null/'' = unmapped) */
    public array $mapping = [];

    public ?string $error = null;

    public function mount(): void
    {
        Gate::authorize('edit-content');
    }

    /**
     * Step 1 → 2: validate the upload, read its text, call DetectSchemaAction.
     */
    public function detect(DetectSchemaAction $action): void
    {
        Gate::authorize('edit-content');

        $this->validate([
            'file' => 'required|file|mimes:csv,txt|mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel|max:5120',
            'entityType' => 'required|in:'.implode(',', array_map(fn ($c) => $c->value, MigrationEntityType::cases())),
        ]);

        $this->error = null;

        // Read the CSV text directly — the action stores the full payload itself.
        // We never touch getClientOriginalName(); the temp file path is opaque.
        $payload = file_get_contents($this->file->getRealPath());
        if ($payload === false || trim($payload) === '') {
            $this->addError('file', 'The uploaded file is empty or unreadable.');

            return;
        }

        try {
            $run = $action->execute(
                user: auth()->user(),
                payload: $payload,
                source: MigrationSource::Csv,
                entityType: MigrationEntityType::from($this->entityType),
            );
        } catch (\Throwable $e) {
            $this->error = 'Schema detection failed: '.$e->getMessage();

            return;
        }

        $this->runId = $run->id;
        $this->mapping = $run->proposed_mapping ?? [];
        $this->step = 2;
    }

    /**
     * Step 2 → 3: submit the confirmed (possibly user-adjusted) mapping.
     */
    public function import(ExecuteMigrationAction $action): void
    {
        Gate::authorize('edit-content');

        if ($this->runId === null) {
            $this->error = 'No migration run in progress.';

            return;
        }

        $run = MigrationRun::query()
            ->where('team_id', auth()->user()->current_team_id)
            ->findOrFail($this->runId);

        try {
            $run = $action->execute($run, $this->mapping);
        } catch (\Throwable $e) {
            $this->error = 'Import failed: '.$e->getMessage();

            return;
        }

        $this->runId = $run->id;
        $this->step = 3;
    }

    public function backToUpload(): void
    {
        $this->reset(['step', 'file', 'runId', 'mapping', 'error']);
    }

    public function render()
    {
        $run = $this->runId !== null
            ? MigrationRun::query()
                ->where('team_id', auth()->user()->current_team_id)
                ->find($this->runId)
            : null;

        $importer = app(ImporterRegistry::class)->resolve(MigrationEntityType::from($this->entityType));

        return view('livewire.migration.import-wizard-page', [
            'run' => $run,
            'entityTypes' => MigrationEntityType::cases(),
            'supportedAttributes' => $importer->supportedAttributes(),
        ])->layout('layouts.app', ['header' => 'Import Data']);
    }
}
