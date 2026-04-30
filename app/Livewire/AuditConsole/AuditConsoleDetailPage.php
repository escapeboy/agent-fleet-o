<?php

namespace App\Livewire\AuditConsole;

use FleetQ\BorunaAudit\Models\AuditableDecision;
use FleetQ\BorunaAudit\Services\BundleStorage;
use FleetQ\BorunaAudit\Services\BundleVerifier;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

#[Layout('layouts.app')]
class AuditConsoleDetailPage extends Component
{
    public AuditableDecision $decision;

    public function mount(string $decision): void
    {
        abort_unless(auth()->check() && Gate::allows('manage-team'), 403);

        $teamId = auth()->user()->currentTeam->id;

        // Scoped by team_id: cross-tenant guesses return 404, not 403.
        $this->decision = AuditableDecision::where('id', $decision)
            ->where('team_id', $teamId)
            ->firstOrFail();
    }

    public function verifyBundle(): void
    {
        $teamId = auth()->user()->currentTeam->id;
        $verifier = app(BundleVerifier::class);
        $result = $verifier->verify($this->decision, $teamId);

        $this->decision->refresh();

        if ($result->passed) {
            session()->flash('success', 'Bundle verification passed.');
        } else {
            session()->flash('error', 'Bundle verification failed: '.($result->errorMessage ?? 'Unknown error'));
        }
    }

    public function replayBundle(): void
    {
        abort_unless(Gate::check('manage-team'), 403);

        session()->flash('info', 'Bundle replay is not yet implemented in this version.');
    }

    public function downloadBundle(): BinaryFileResponse
    {
        abort_unless($this->decision->bundle_path !== null, 404);

        $storage = app(BundleStorage::class);
        $absolutePath = $storage->bundleAbsolutePath($this->decision->bundle_path);

        abort_unless(is_dir($absolutePath), 404);

        $zipPath = sys_get_temp_dir().'/boruna_bundle_'.$this->decision->run_id.'.zip';
        $zip = new \ZipArchive;

        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Could not create bundle archive.');
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absolutePath, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $zip->addFile($file->getPathname(), $file->getFilename());
            }
        }

        $zip->close();

        return response()->download($zipPath, 'boruna_bundle_'.$this->decision->run_id.'.zip')
            ->deleteFileAfterSend();
    }

    public function render()
    {
        $isAdmin = Gate::check('manage-team');
        $evidence = $this->decision->evidence ?? [];
        $evidenceChain = $evidence['audit_log'] ?? [];
        $llmCalls = $evidence['llm_calls'] ?? [];

        if (! $isAdmin) {
            $llmCalls = array_map(function ($call) {
                return array_merge($call, ['prompt' => '[redacted]', 'response' => '[redacted]']);
            }, $llmCalls);
        }

        $cytoElements = $this->buildCytoElements();

        return view('livewire.audit-console.detail', compact('isAdmin', 'evidenceChain', 'llmCalls', 'cytoElements'))
            ->title('Audit Decision — '.$this->decision->workflow_name);
    }

    private function buildCytoElements(): array
    {
        $nodes = [
            ['data' => ['id' => 'start', 'label' => 'Start']],
            ['data' => ['id' => 'workflow', 'label' => $this->decision->workflow_name]],
            ['data' => ['id' => 'end', 'label' => $this->decision->status->value]],
        ];

        $edges = [
            ['data' => ['source' => 'start', 'target' => 'workflow']],
            ['data' => ['source' => 'workflow', 'target' => 'end']],
        ];

        return ['nodes' => $nodes, 'edges' => $edges];
    }
}
