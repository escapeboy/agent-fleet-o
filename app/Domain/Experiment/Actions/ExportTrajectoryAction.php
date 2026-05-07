<?php

namespace App\Domain\Experiment\Actions;

use App\Domain\Experiment\Models\Experiment;
use Illuminate\Support\Str;

class ExportTrajectoryAction
{
    public function execute(Experiment $experiment, string $format = 'csv'): array
    {
        $steps = $experiment->playbookSteps()->with(['agent', 'skill', 'crew'])->orderBy('order')->get();

        $rows = $steps->map(fn ($step) => [
            'step_order' => $step->order,
            'step_type' => $step->execution_mode->value,
            'agent_name' => $step->agent->name ?? '',
            'skill_name' => $step->skill->name ?? '',
            'crew_name' => $step->crew->name ?? '',
            'status' => $step->status,
            'duration_ms' => $step->duration_ms ?? 0,
            'cost_credits' => $step->cost_credits ?? 0,
            'started_at' => $step->started_at?->toIso8601String() ?? '',
            'completed_at' => $step->completed_at?->toIso8601String() ?? '',
            'output_preview' => Str::limit($this->extractText($step->output), 200),
        ]);

        $filename = 'trajectory-'.$experiment->id.'-'.now()->format('Ymd-His').($format === 'csv' ? '.csv' : '.jsonl');

        if ($format === 'jsonl') {
            $jsonlRows = $rows->map(fn ($row) => array_merge($row, [
                'output_full' => Str::limit($this->extractText(
                    $steps->firstWhere('order', $row['step_order'])?->output,
                ), 8192),
            ]));

            return [
                'content' => $jsonlRows->map(fn ($row) => json_encode($row))->implode("\n"),
                'filename' => $filename,
                'mime' => 'application/x-ndjson',
            ];
        }

        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, array_keys($rows->first() ?? []));
        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return [
            'content' => $content,
            'filename' => $filename,
            'mime' => 'text/csv',
        ];
    }

    private function extractText(?array $output): string
    {
        if ($output === null) {
            return '';
        }

        foreach (['result', 'content', 'text', 'body', 'output'] as $key) {
            if (isset($output[$key]) && is_string($output[$key]) && $output[$key] !== '') {
                return $output[$key];
            }
        }

        return Str::limit(json_encode($output), 1000);
    }
}
