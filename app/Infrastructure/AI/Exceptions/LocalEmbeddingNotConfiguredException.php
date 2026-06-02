<?php

namespace App\Infrastructure\AI\Exceptions;

use RuntimeException;

/**
 * Thrown when config('memory.embedding_driver') is 'local' but no local
 * embedding provider has been bound. This is the documented extension point
 * for a future FFI/local backend (e.g. NeuraPHP over embedding.cpp). Until
 * such a provider ships, selecting the local driver fails fast with this
 * exception; embedding-optional paths (e.g. SemanticCache) catch it and
 * degrade gracefully rather than treating it as fatal.
 */
class LocalEmbeddingNotConfiguredException extends RuntimeException
{
    public function __construct(string $message = 'No local embedding provider is bound. Set MEMORY_EMBEDDING_DRIVER=cloud or bind a LocalEmbeddingProvider to EmbeddingProviderInterface.')
    {
        parent::__construct($message);
    }
}
