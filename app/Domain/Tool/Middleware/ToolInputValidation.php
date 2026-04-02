<?php

namespace App\Domain\Tool\Middleware;

use App\Domain\Tool\Contracts\ToolExecutionMiddlewareInterface;
use App\Domain\Tool\DTOs\ToolExecutionContext;
use Closure;

/**
 * Validates tool input against configured constraints.
 */
class ToolInputValidation implements ToolExecutionMiddlewareInterface
{
    public function handle(ToolExecutionContext $context, Closure $next): array
    {
        $maxInputSize = $context->tool->config['max_input_size'] ?? null;

        if ($maxInputSize) {
            $inputSize = mb_strlen(json_encode($context->input));
            if ($inputSize > $maxInputSize) {
                return [
                    'error' => "Input too large ({$inputSize} bytes, max {$maxInputSize}).",
                    'blocked_by' => 'input_validation',
                ];
            }
        }

        // Check for blocked patterns
        $blockedPatterns = $context->tool->config['blocked_input_patterns'] ?? [];
        $inputJson = json_encode($context->input);

        foreach ($blockedPatterns as $pattern) {
            if (preg_match($pattern, $inputJson)) {
                return [
                    'error' => 'Input contains a blocked pattern.',
                    'blocked_by' => 'input_validation',
                ];
            }
        }

        return $next($context);
    }
}
