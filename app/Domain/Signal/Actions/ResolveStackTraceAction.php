<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Services\SourceMapResolver;
use App\Domain\Signal\Services\StackTraceParser;

class ResolveStackTraceAction
{
    public function __construct(
        private readonly StackTraceParser $parser,
        private readonly SourceMapResolver $resolver,
    ) {}

    /**
     * Extract console errors from signal payload, resolve stack frames, write back to payload.
     */
    public function execute(Signal $signal): void
    {
        $payload = $signal->payload ?? [];
        $consoleLog = $payload['console_log'] ?? [];

        if (empty($consoleLog) || ! is_array($consoleLog)) {
            return;
        }

        $errors = $this->parser->extractErrors($consoleLog);

        if (empty($errors)) {
            return;
        }

        $teamId = $signal->team_id;
        $project = $signal->project_key ?? ($payload['project'] ?? null);
        $release = $payload['deploy_commit'] ?? null;

        $resolvedErrors = [];

        foreach ($errors as $error) {
            $resolvedFrames = [];
            $firstProjectFrame = null;

            foreach ($error['frames'] as $frame) {
                $resolved = null;

                if ($project && $release) {
                    $resolved = $this->resolver->resolve($teamId, $project, $release, $frame);
                }

                $entry = $resolved ?? [
                    'file' => $frame['file'],
                    'line' => $frame['line'],
                    'column' => $frame['column'],
                    'function' => $frame['function'],
                    'isProjectCode' => $this->parser->isProjectFrame($frame),
                ];

                $resolvedFrames[] = $entry;

                if ($firstProjectFrame === null && $entry['isProjectCode']) {
                    $firstProjectFrame = [
                        'file' => $entry['file'],
                        'line' => $entry['line'],
                        'function' => $entry['function'],
                    ];
                }
            }

            $resolvedErrors[] = [
                'type' => $error['type'],
                'message' => $error['message'],
                'raw_stack' => $error['raw_stack'],
                'resolved_frames' => $resolvedFrames,
                'firstProjectFrame' => $firstProjectFrame,
            ];
        }

        $payload['resolved_errors'] = $resolvedErrors;
        $signal->update(['payload' => $payload]);
    }
}
