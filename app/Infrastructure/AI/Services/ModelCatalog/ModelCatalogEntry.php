<?php

namespace App\Infrastructure\AI\Services\ModelCatalog;

/**
 * One normalized model from a managed provider's /models endpoint.
 *
 * Pricing is expressed in USD per 1,000,000 tokens to match the convention
 * used by config('llm_pricing.providers.*') (input_usd_per_mtok / output_usd_per_mtok).
 * `priced` is false when the provider returned no usable pricing — the caller
 * must NOT bill such a model at $0 (ledger-integrity guard).
 */
final readonly class ModelCatalogEntry
{
    public function __construct(
        public string $id,
        public string $label,
        public ?float $inputUsdPerMtok,
        public ?float $outputUsdPerMtok,
        public ?int $context = null,
    ) {}

    public function priced(): bool
    {
        return $this->inputUsdPerMtok !== null && $this->outputUsdPerMtok !== null;
    }

    /**
     * Shape consumed by ProviderResolver::availableProviders() model dropdowns.
     *
     * @return array{label: string, input_cost: float, output_cost: float}
     */
    public function toProviderModel(): array
    {
        return [
            'label' => $this->label,
            'input_cost' => $this->inputUsdPerMtok ?? 0.0,
            'output_cost' => $this->outputUsdPerMtok ?? 0.0,
        ];
    }

    /**
     * Shape consumed by llm_pricing.providers.{provider}.{model} (CostCalculator).
     *
     * @return array{tier: string, input_usd_per_mtok: float, output_usd_per_mtok: float, last_verified_at: string}
     */
    public function toPricingEntry(string $verifiedAt): array
    {
        return [
            'tier' => 'default',
            'input_usd_per_mtok' => $this->inputUsdPerMtok ?? 0.0,
            'output_usd_per_mtok' => $this->outputUsdPerMtok ?? 0.0,
            'last_verified_at' => $verifiedAt,
        ];
    }
}
