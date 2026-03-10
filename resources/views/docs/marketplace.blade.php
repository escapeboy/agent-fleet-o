<x-layouts.docs
    title="Marketplace"
    description="Browse and install pre-built skills, agents, and workflows from the FleetQ marketplace. Learn how to publish your own listings."
    page="marketplace"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Marketplace — Pre-Built Skills &amp; Agents</h1>
    <p class="mt-4 text-gray-600">
        The <strong>Marketplace</strong> is a curated library of skills, agents, and workflows contributed by
        the FleetQ community and official platform team. Install a listing and it becomes part of your team's
        library in seconds — no setup required.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>A content team discovers the "SEO Content Analyzer" skill in the marketplace.
        One click installs it. They assign it to their Blog Writer agent — the agent can now score every draft
        against SEO best practices before publishing.</em>
    </p>

    {{-- Browsing --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Browsing the marketplace</h2>
    <p class="mt-2 text-sm text-gray-600">
        Visit <a href="/marketplace" class="text-primary-600 hover:underline">/marketplace</a> (no login required for browsing).
        Filter by category (Content, Research, Sales, DevOps, etc.), listing type (skill, agent, workflow),
        or search by keyword.
    </p>
    <p class="mt-2 text-sm text-gray-600">
        Listings marked with a <strong>verified badge</strong> are published or audited by the FleetQ platform team.
    </p>

    {{-- Installing --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Installing a listing</h2>
    <p class="mt-2 text-sm text-gray-600">
        Click <strong>Install</strong> on any listing. FleetQ creates a local copy in your team's library —
        a snapshot of the listing at the time of installation.
        Updates to the original listing don't affect your installed copy unless you re-install.
    </p>

    <x-docs.code lang="bash">
curl -X POST {{ url('/api/v1/marketplace/seo-content-analyzer/install') }} \
  -H "Authorization: Bearer YOUR_TOKEN"</x-docs.code>

    {{-- Publishing --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Publishing your own listings</h2>
    <x-docs.badge text="Pro" color="purple" />
    <p class="mt-3 text-sm text-gray-600">
        Share your skills, agents, and workflows with the community.
        Navigate to <a href="/marketplace/publish" class="text-primary-600 hover:underline">/marketplace/publish</a>
        or use the API:
    </p>

    <x-docs.code lang="json" title="Publish via API">
POST /api/v1/marketplace
{
  "title": "Competitive Intelligence Monitor",
  "slug": "competitive-intelligence-monitor",
  "description": "Monitors competitor websites and RSS feeds for product launches and pricing changes.",
  "listing_type": "agent",
  "source_id": "YOUR_AGENT_ID",
  "visibility": "public",
  "category": "Research"
}</x-docs.code>

    <p class="mt-3 text-sm text-gray-600">
        Listings go through a brief review before becoming publicly visible.
        Private listings are only visible to your team.
    </p>

    {{-- Reviews --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Reviews &amp; ratings</h2>
    <p class="mt-2 text-sm text-gray-600">
        After installing and using a listing, you can leave a star rating and written review.
        Reviews help the community discover high-quality listings.
    </p>

    <x-docs.code lang="bash">
curl -X POST {{ url('/api/v1/marketplace/seo-content-analyzer/reviews') }} \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{"rating": 5, "body": "Works perfectly for our content pipeline. Saved hours of manual SEO checking."}'</x-docs.code>
</x-layouts.docs>
