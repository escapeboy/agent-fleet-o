<?php

namespace App\Domain\Website\Services;

use App\Domain\Shared\Services\PluginRegistry;
use FleetQ\PluginSdk\Contracts\WebsiteBlockProvider;

/**
 * Discovers all registered FleetQ plugins that implement WebsiteBlockProvider
 * and aggregates their GrapesJS block definitions, scripts, and styles.
 *
 * Bound as a singleton in AppServiceProvider.
 */
class WebsiteBlockRegistry
{
    public function __construct(
        private readonly PluginRegistry $plugins,
    ) {}

    /**
     * All GrapesJS block definitions: built-in defaults merged with plugin blocks.
     * Plugin blocks take priority over built-ins with the same key.
     *
     * @return array<string, array<string, mixed>>
     */
    public function blocks(): array
    {
        $blocks = $this->defaultBlocks();

        foreach ($this->providers() as $provider) {
            foreach ($provider->getBlocks() as $type => $definition) {
                $blocks[$type] = $definition;
            }
        }

        return $blocks;
    }

    /**
     * Built-in Tailwind CSS block templates available without any plugin.
     *
     * @return array<string, array<string, mixed>>
     */
    public function defaultBlocks(): array
    {
        return [
            'hero' => [
                'id' => 'hero',
                'label' => 'Hero',
                'category' => 'Layout',
                'html' => '<section class="bg-white py-20 text-center"><div class="mx-auto max-w-3xl px-6"><h1 class="text-4xl font-bold tracking-tight text-gray-900">Your Headline Here</h1><p class="mt-4 text-lg text-gray-600">A short description of what you offer and why visitors should care.</p><a href="#" class="mt-8 inline-block rounded-lg bg-blue-600 px-6 py-3 text-base font-semibold text-white hover:bg-blue-700">Get Started</a></div></section>',
            ],
            'features' => [
                'id' => 'features',
                'label' => 'Features',
                'category' => 'Content',
                'html' => '<section class="bg-gray-50 py-16"><div class="mx-auto max-w-5xl px-6"><h2 class="mb-10 text-center text-2xl font-bold text-gray-900">Why Choose Us</h2><div class="grid grid-cols-1 gap-8 md:grid-cols-3"><div class="rounded-xl bg-white p-6 shadow-sm"><div class="mb-3 text-3xl">⚡</div><h3 class="mb-2 font-semibold text-gray-900">Fast</h3><p class="text-sm text-gray-600">Built for speed and performance.</p></div><div class="rounded-xl bg-white p-6 shadow-sm"><div class="mb-3 text-3xl">🔒</div><h3 class="mb-2 font-semibold text-gray-900">Secure</h3><p class="text-sm text-gray-600">Enterprise-grade security by default.</p></div><div class="rounded-xl bg-white p-6 shadow-sm"><div class="mb-3 text-3xl">🌍</div><h3 class="mb-2 font-semibold text-gray-900">Scalable</h3><p class="text-sm text-gray-600">Grows with your business.</p></div></div></div></section>',
            ],
            'testimonials' => [
                'id' => 'testimonials',
                'label' => 'Testimonials',
                'category' => 'Content',
                'html' => '<section class="bg-white py-16"><div class="mx-auto max-w-4xl px-6"><h2 class="mb-10 text-center text-2xl font-bold text-gray-900">What Our Customers Say</h2><div class="grid grid-cols-1 gap-6 md:grid-cols-2"><blockquote class="rounded-xl border border-gray-100 bg-gray-50 p-6"><p class="text-gray-700">"This product changed everything for us. Highly recommended!"</p><footer class="mt-4 text-sm font-semibold text-gray-900">— Jane Doe, Acme Corp</footer></blockquote><blockquote class="rounded-xl border border-gray-100 bg-gray-50 p-6"><p class="text-gray-700">"Incredible support and a beautiful product. We love it."</p><footer class="mt-4 text-sm font-semibold text-gray-900">— John Smith, Beta Inc</footer></blockquote></div></div></section>',
            ],
            'cta' => [
                'id' => 'cta',
                'label' => 'Call to Action',
                'category' => 'Layout',
                'html' => '<section class="bg-blue-600 py-16 text-center"><div class="mx-auto max-w-2xl px-6"><h2 class="text-3xl font-bold text-white">Ready to Get Started?</h2><p class="mt-3 text-blue-100">Join thousands of happy customers today.</p><a href="#" class="mt-6 inline-block rounded-lg bg-white px-6 py-3 text-base font-semibold text-blue-600 hover:bg-blue-50">Start Free Trial</a></div></section>',
            ],
            'footer' => [
                'id' => 'footer',
                'label' => 'Footer',
                'category' => 'Layout',
                'html' => '<footer class="bg-gray-900 py-10 text-center text-sm text-gray-400"><div class="mx-auto max-w-5xl px-6"><div class="mb-4 flex justify-center gap-6"><a href="#" class="hover:text-white">Home</a><a href="#" class="hover:text-white">About</a><a href="#" class="hover:text-white">Contact</a></div><p>&copy; 2025 Your Company. All rights reserved.</p></div></footer>',
            ],
        ];
    }

    /**
     * All editor JS script URLs from every active website plugin.
     *
     * @return list<string>
     */
    public function scripts(): array
    {
        return array_values(array_unique(array_merge(
            ...array_map(fn ($p) => $p->getEditorScripts(), $this->providers()),
        )));
    }

    /**
     * All editor CSS stylesheet URLs from every active website plugin.
     *
     * @return list<string>
     */
    public function styles(): array
    {
        return array_values(array_unique(array_merge(
            ...array_map(fn ($p) => $p->getEditorStyles(), $this->providers()),
        )));
    }

    /**
     * @return list<WebsiteBlockProvider>
     */
    private function providers(): array
    {
        return $this->plugins
            ->all()
            ->filter(fn ($plugin) => $plugin instanceof WebsiteBlockProvider)
            ->values()
            ->all();
    }
}
