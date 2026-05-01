<?php

namespace App\Http\Controllers\Api\V1\Concerns;

/**
 * Marker trait for API controllers that document their responses via Scramble attributes.
 *
 * Implementing controllers should annotate public methods with:
 *   - return SomeResource — for Scramble to infer schema
 *   - #[\Dedoc\Scramble\Attributes\SuccessResponse] if overriding inference
 *
 * This is the "signature = contract" convention: return types are the contract.
 */
trait DocumentsResponses {}
