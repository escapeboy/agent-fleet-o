<?php

namespace App\Domain\Search\Exceptions;

use RuntimeException;

/**
 * Thrown when the configured web-search provider cannot run (missing URL/key,
 * upstream failure). Callers surface this as a failed-precondition, not a crash.
 */
class WebSearchUnavailableException extends RuntimeException {}
