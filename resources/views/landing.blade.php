<x-layouts.public
    title="Agent Fleet — AI Agent Mission Control"
    description="Design multi-agent crews, build visual workflows, and deploy experiments with human-in-the-loop approval and built-in cost controls. Open source."
    keywords="AI agents, multi-agent platform, AI workflow builder, agent orchestration, AI automation, MCP"
>
    <x-slot:head>
        <script type="application/ld+json">
        {
            "@@context": "https://schema.org",
            "@@type": "WebSite",
            "name": "Agent Fleet",
            "url": "{{ url('/') }}",
            "description": "AI Agent Mission Control Platform"
        }
        </script>
        <script type="application/ld+json">
        {
            "@@context": "https://schema.org",
            "@@type": "SoftwareApplication",
            "name": "Agent Fleet",
            "applicationCategory": "DeveloperApplication",
            "operatingSystem": "Web",
            "description": "Design multi-agent crews, build visual workflows, and deploy experiments with human-in-the-loop approval and cost controls.",
            "offers": {
                "@@type": "Offer",
                "price": "0",
                "priceCurrency": "EUR"
            }
        }
        </script>
    </x-slot:head>

    <x-landing.nav />
    <x-landing.hero />
    <x-landing.stats />
    <x-landing.features />
    <x-landing.how-it-works />
    <x-landing.faq />
    <x-landing.cta />
    <x-landing.footer />
</x-layouts.public>
