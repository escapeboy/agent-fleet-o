<?php

namespace App\Infrastructure\Compute\DTOs;

/**
 * Data transfer object for a compute job.
 *
 * IMPORTANT: Contains decrypted credentials. Never serialize this DTO to a queue.
 * Credentials must be resolved immediately before job dispatch, inside the job handler.
 */
readonly class ComputeJobDTO
{
    public function __construct(
        /** Provider slug: 'runpod', 'replicate', 'fal', 'modal', 'vast' */
        public string $provider,

        /** Provider-specific endpoint/model/deployment identifier */
        public string $endpointId,

        /** Input data to send to the endpoint */
        public array $input,

        /** Decrypted API credentials — NEVER serialize to queue */
        public array $credentials,

        /** Execution timeout in seconds */
        public int $timeoutSeconds = 90,

        /** Use synchronous execution when the provider supports it */
        public bool $useSync = true,

        /** Optional key remapping: ['endpoint_key' => 'skill_input_key'] */
        public array $inputMapping = [],

        /** Provider-specific extra options (gpu_type_id, image_name, etc.) */
        public array $options = [],
    ) {}
}
