<x-layouts.public
    title="Agent Fleet — AI Agent Mission Control"
    description="Agent Fleet: build AI agents, assemble multi-agent crews, and deploy visual workflows with approval gates and cost controls. Open source."
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
            "url": "{{ url('/') }}",
            "applicationCategory": "DeveloperApplication",
            "operatingSystem": "Web",
            "description": "Build AI agents, assemble multi-agent crews, and deploy visual workflows with approval gates and cost controls.",
            "offers": {
                "@@type": "Offer",
                "price": "0",
                "priceCurrency": "EUR"
            },
            "author": {
                "@@type": "Organization",
                "name": "PriceX Ltd.",
                "url": "{{ url('/') }}"
            }
        }
        </script>
        <script type="application/ld+json">
        {
            "@@context": "https://schema.org",
            "@@type": "Organization",
            "name": "Agent Fleet",
            "url": "{{ url('/') }}",
            "sameAs": [
                "https://github.com/escapeboy/agent-fleet-o"
            ]
        }
        </script>
        <script type="application/ld+json">
        {
            "@@context": "https://schema.org",
            "@@type": "FAQPage",
            "mainEntity": [
                {
                    "@@type": "Question",
                    "name": "What is Agent Fleet?",
                    "acceptedAnswer": {
                        "@@type": "Answer",
                        "text": "Agent Fleet is an AI Agent Mission Control Platform. You create AI agents with specific roles and goals, assemble them into multi-agent crews, and orchestrate their work through visual workflows. Every run includes human-in-the-loop approval gates and budget controls, so you stay in charge."
                    }
                },
                {
                    "@@type": "Question",
                    "name": "Is Agent Fleet open source?",
                    "acceptedAnswer": {
                        "@@type": "Answer",
                        "text": "Yes. The community edition is fully open source under the MIT license. You can self-host it on your own infrastructure. A managed cloud edition is also available with additional features like team management and billing."
                    }
                },
                {
                    "@@type": "Question",
                    "name": "What AI providers are supported?",
                    "acceptedAnswer": {
                        "@@type": "Answer",
                        "text": "Agent Fleet supports three cloud providers — Anthropic (Claude), OpenAI (GPT-4o), and Google (Gemini) — with automatic failover between them. You bring your own API keys, so your credentials stay on your infrastructure. Local agents like Claude Code and OpenAI Codex are also supported at zero platform cost."
                    }
                },
                {
                    "@@type": "Question",
                    "name": "Can I self-host Agent Fleet?",
                    "acceptedAnswer": {
                        "@@type": "Answer",
                        "text": "Yes. Agent Fleet ships as a Docker stack with PHP 8.4, PostgreSQL 17, and Redis 7. Run the install wizard and it walks you through database setup, admin account creation, AI provider keys, and default agent configuration in under five minutes."
                    }
                },
                {
                    "@@type": "Question",
                    "name": "How does budget control work?",
                    "acceptedAnswer": {
                        "@@type": "Answer",
                        "text": "Every LLM call has a credit cost calculated from token usage. You set budget caps at the global and per-experiment level. The platform alerts you at 80% usage and automatically pauses operations when the budget is exhausted — no surprise bills."
                    }
                },
                {
                    "@@type": "Question",
                    "name": "What is MCP integration?",
                    "acceptedAnswer": {
                        "@@type": "Answer",
                        "text": "Agent Fleet includes a Model Context Protocol (MCP) server with 76 tools across 15 domains. This means external AI agents — such as Claude Code, OpenAI Codex, or Cursor — can manage your Agent Fleet instance directly: creating agents, triggering workflows, checking budgets, and browsing the marketplace."
                    }
                },
                {
                    "@@type": "Question",
                    "name": "Is my data secure?",
                    "acceptedAnswer": {
                        "@@type": "Answer",
                        "text": "All API keys are encrypted at rest with AES-256. With Bring Your Own Key (BYOK), your LLM credentials never leave your infrastructure. The platform enforces rate limiting, budget caps, target blacklists, a complete audit trail, and role-based access control with four permission levels."
                    }
                }
            ]
        }
        </script>
    </x-slot:head>

    <x-landing.nav />
    <x-landing.hero />
    <x-landing.stats />
    <x-landing.features />
    <x-landing.how-it-works />
    <x-landing.quickstart />
    <x-landing.faq />
    <x-landing.cta />
    <x-landing.footer />
</x-layouts.public>
