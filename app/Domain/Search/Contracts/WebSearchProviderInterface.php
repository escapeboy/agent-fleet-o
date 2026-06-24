<?php

namespace App\Domain\Search\Contracts;

/**
 * Provider-agnostic web search. Implementations normalize results to a common
 * shape so agents/tools never depend on a specific search vendor.
 */
interface WebSearchProviderInterface
{
    /**
     * @param  array<string, mixed>  $options  e.g. ['max_results' => 5, 'categories' => ['general']]
     * @return array<int, array{title: string, url: string, snippet: string}>
     */
    public function search(string $query, array $options = []): array;

    public function name(): string;
}
