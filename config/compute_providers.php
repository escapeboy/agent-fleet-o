<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Compute Provider
    |--------------------------------------------------------------------------
    |
    | The default provider used when no explicit provider is specified in the
    | GpuCompute skill configuration.
    |
    | Supported: 'runpod', 'null'
    | Coming soon: 'replicate', 'fal', 'modal', 'vast'
    |
    */
    'default' => env('COMPUTE_PROVIDER', 'runpod'),

    /*
    |--------------------------------------------------------------------------
    | Registered Providers
    |--------------------------------------------------------------------------
    |
    | Maps provider slugs to their display labels. Used for validation in skill
    | configuration forms and credential management UI.
    |
    */
    'providers' => [
        'runpod' => ['label' => 'RunPod', 'driver' => 'runpod'],
        'replicate' => ['label' => 'Replicate', 'driver' => 'replicate'],
        'fal' => ['label' => 'Fal.ai', 'driver' => 'fal'],
        'modal' => ['label' => 'Modal Labs', 'driver' => 'modal'],
        'vast' => ['label' => 'Vast.ai', 'driver' => 'vast'],
    ],

    /*
    |--------------------------------------------------------------------------
    | RunPod GPU Credit Prices
    |--------------------------------------------------------------------------
    |
    | Approximate RunPod GPU prices in platform credits per hour.
    | 1 credit = $0.001 USD. Prices reflect RunPod's community cloud rates.
    |
    | Used for analytics / cost tracking only — actual charges are billed
    | directly to the user's RunPod account, not platform credits.
    |
    | Update if RunPod changes pricing: https://www.runpod.io/gpu-instance/pricing
    |
    */
    'gpu_credits_per_hour' => [
        'NVIDIA RTX 4090' => 440,   // ~$0.44/hr
        'NVIDIA RTX 3090' => 230,   // ~$0.23/hr
        'NVIDIA RTX A4500' => 250,   // ~$0.25/hr
        'NVIDIA RTX A5000' => 270,   // ~$0.27/hr
        'NVIDIA RTX A6000' => 780,   // ~$0.78/hr
        'NVIDIA A40' => 770,   // ~$0.77/hr
        'NVIDIA L40' => 1140,  // ~$1.14/hr
        'NVIDIA A100 80GB' => 1890,  // ~$1.89/hr
        'NVIDIA A100 SXM' => 2490,  // ~$2.49/hr
        'NVIDIA H100 PCIe' => 2890,  // ~$2.89/hr
        'NVIDIA H100 SXM' => 3490,  // ~$3.49/hr
        'NVIDIA H100 NVL' => 3990,  // ~$3.99/hr
        'default' => 500,   // Fallback for unknown GPU types
    ],

    /*
    |--------------------------------------------------------------------------
    | Spot Instance Discount
    |--------------------------------------------------------------------------
    |
    | Spot (interruptible) pods are significantly cheaper but may be preempted.
    | Apply this multiplier to the GPU price estimate.
    |
    */
    'spot_discount' => 0.4, // Spot instances are roughly 60% cheaper

    /*
    |--------------------------------------------------------------------------
    | Pod Lifecycle Defaults (RunPod)
    |--------------------------------------------------------------------------
    */
    'pod_defaults' => [
        'startup_timeout_seconds' => 300,  // 5 minutes max wait for pod to start
        'poll_interval_seconds' => 10,   // How often to check pod status
        'estimated_minutes' => 10,   // Default estimated runtime for cost tracking
        'container_disk_gb' => 20,   // Default container disk
        'gpu_count' => 1,    // Default GPU count
    ],
];
