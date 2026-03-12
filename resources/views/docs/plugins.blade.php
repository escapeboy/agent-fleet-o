<x-layouts.docs
    title="Plugin System"
    description="Extend FleetQ with Composer packages. Plugins add MCP tools, signal connectors, outbound channels, AI middleware, and UI panels — all enabled/disabled without touching core code."
    page="plugins"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Plugin System — Extend FleetQ</h1>
    <p class="mt-4 text-gray-600">
        FleetQ's plugin system lets you package custom functionality as a standard Composer package and drop it
        into any self-hosted installation. Plugins are first-class citizens: they register MCP tools, add signal
        connectors, contribute outbound channels, inject AI middleware, extend the sidebar, and add dashboard widgets
        — all without touching core application code.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>A team builds a <code>fleet-crm-sync</code> plugin that ingests HubSpot
        contact signals, adds a <code>crm_contact_update</code> MCP tool, and displays a CRM health widget on
        the dashboard. Installing it is a single <code>composer require</code>.</em>
    </p>

    {{-- How it works --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">How it works</h2>
    <p class="mt-2 text-sm text-gray-600">
        A plugin is a Composer package that:
    </p>
    <ol class="mt-2 list-decimal pl-5 space-y-1 text-sm text-gray-600">
        <li>Implements the <code class="rounded bg-gray-100 px-1 text-xs">FleetPlugin</code> interface.</li>
        <li>Ships a service provider that extends <code class="rounded bg-gray-100 px-1 text-xs">FleetPluginServiceProvider</code>.</li>
        <li>Declares itself in <code class="rounded bg-gray-100 px-1 text-xs">composer.json</code> using Laravel auto-discovery with a custom <code class="rounded bg-gray-100 px-1 text-xs">fleet</code> key.</li>
    </ol>
    <p class="mt-3 text-sm text-gray-600">
        On boot, FleetQ registers the plugin in its <code class="rounded bg-gray-100 px-1 text-xs">PluginRegistry</code>, upserts a row in
        <code class="rounded bg-gray-100 px-1 text-xs">plugin_states</code>, and wires up all declared capabilities.
        If the plugin is disabled in Settings, the boot phase is skipped entirely — no event listeners, no tools.
    </p>

    {{-- composer.json --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Package manifest</h2>
    <p class="mt-2 text-sm text-gray-600">
        Declare your plugin in <code class="rounded bg-gray-100 px-1 text-xs">composer.json</code> alongside the standard Laravel
        auto-discovery entry:
    </p>

    <x-docs.code lang="json" title="your-plugin/composer.json">
{
  "name": "acme/fleet-crm-sync",
  "description": "FleetQ plugin — HubSpot CRM sync",
  "type": "library",
  "require": {},
  "autoload": {
    "psr-4": { "Acme\\CrmSync\\": "src/" }
  },
  "extra": {
    "laravel": {
      "providers": ["Acme\\CrmSync\\CrmSyncServiceProvider"]
    },
    "fleet": {
      "plugin":      "acme-crm-sync",
      "name":        "CRM Sync",
      "min-version": "1.0.0"
    }
  }
}</x-docs.code>

    {{-- FleetPlugin interface --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">The FleetPlugin interface</h2>
    <p class="mt-2 text-sm text-gray-600">
        Your plugin class implements <code class="rounded bg-gray-100 px-1 text-xs">App\Contracts\FleetPlugin</code>:
    </p>

    <x-docs.code lang="php" title="src/CrmSyncPlugin.php">
namespace Acme\CrmSync;

use App\Contracts\FleetPlugin;

class CrmSyncPlugin implements FleetPlugin
{
    public function getId(): string      { return 'acme-crm-sync'; }
    public function getName(): string    { return 'CRM Sync'; }
    public function getVersion(): string { return '1.0.0'; }

    public function register(): void
    {
        // Container bindings only — no event listeners here
    }

    public function boot(): void
    {
        // Routes, blade directives, macros
    }
}</x-docs.code>

    {{-- Service provider --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">The service provider</h2>
    <p class="mt-2 text-sm text-gray-600">
        Extend <code class="rounded bg-gray-100 px-1 text-xs">FleetPluginServiceProvider</code> and declare your capabilities
        as arrays — no manual wiring needed:
    </p>

    <x-docs.code lang="php" title="src/CrmSyncServiceProvider.php">
namespace Acme\CrmSync;

use App\Providers\FleetPluginServiceProvider;
use App\Contracts\FleetPlugin;

class CrmSyncServiceProvider extends FleetPluginServiceProvider
{
    // Event listeners: EventClass => [ListenerClass, ...]
    protected array $listen = [
        \App\Domain\Signal\Events\SignalIngested::class => [
            \Acme\CrmSync\Listeners\SyncContactOnSignal::class,
        ],
    ];

    // MCP tools — automatically registered in AgentFleetServer
    protected array $mcpTools = [
        \Acme\CrmSync\Mcp\CrmContactUpdateTool::class,
        \Acme\CrmSync\Mcp\CrmContactSearchTool::class,
    ];

    // Inbound signal connectors
    protected array $signals = [
        \Acme\CrmSync\Connectors\HubSpotWebhookConnector::class,
    ];

    // Outbound delivery connectors
    protected array $outbound = [
        \Acme\CrmSync\Connectors\HubSpotOutboundConnector::class,
    ];

    // AI middleware (rate limiting, filtering, enrichment, ...)
    protected array $aiMiddleware = [
        \Acme\CrmSync\Middleware\CrmContextMiddleware::class,
    ];

    // Artisan commands (registered when running in console)
    protected array $commands = [
        \Acme\CrmSync\Console\SyncCrmContacts::class,
    ];

    // Panel extensions (sidebar nav, dashboard widgets, pages)
    protected array $panels = [
        \Acme\CrmSync\Panels\CrmDashboardPanel::class,
    ];

    protected function createPlugin(): FleetPlugin
    {
        return new CrmSyncPlugin;
    }
}</x-docs.code>

    <x-docs.callout type="tip">
        Override <code class="text-xs">bootAddon()</code> instead of <code class="text-xs">boot()</code> if you need extra
        boot logic. The base <code class="text-xs">boot()</code> handles disable checks and all declarative
        registrations before calling <code class="text-xs">bootAddon()</code>.
    </x-docs.callout>

    {{-- Capabilities table --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Declarative capabilities</h2>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Array property</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">What it registers</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">$listen</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Laravel event listeners. Map <code>EventClass => [Listener, ...]</code>.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">$mcpTools</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">MCP tool classes. Tagged as <code>fleet.mcp.tools</code> and auto-appended to AgentFleetServer.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">$signals</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Inbound signal connector classes implementing <code>InputConnectorInterface</code>.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">$outbound</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Outbound delivery connectors implementing <code>OutboundConnectorInterface</code>.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">$aiMiddleware</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">AI gateway middleware (rate limiting, enrichment, filtering). Tagged as <code>fleet.ai.middleware</code>.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">$livewire</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Livewire component namespaces. Map <code>alias => FQNamespace</code>.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">$panels</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Panel extensions that add sidebar links, pages, and dashboard widgets.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">$commands</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Artisan commands. Registered only when running in console.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Optional interfaces --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Optional interfaces</h2>

    {{-- HasPluginSettings --}}
    <h3 class="mt-6 text-base font-semibold text-gray-900">HasPluginSettings — settings UI</h3>
    <p class="mt-2 text-sm text-gray-600">
        Implement <code class="rounded bg-gray-100 px-1 text-xs">HasPluginSettings</code> on your
        <code class="rounded bg-gray-100 px-1 text-xs">FleetPlugin</code> class to inject a settings tab into
        the platform's Settings page:
    </p>

    <x-docs.code lang="php">
class CrmSyncPlugin implements FleetPlugin, HasPluginSettings
{
    // ...

    public function settingsComponent(): string
    {
        return \Acme\CrmSync\Livewire\CrmSettingsForm::class;
    }
}</x-docs.code>

    {{-- PanelExtension --}}
    <h3 class="mt-8 text-base font-semibold text-gray-900">PanelExtension — sidebar, pages, and widgets</h3>
    <p class="mt-2 text-sm text-gray-600">
        Implement <code class="rounded bg-gray-100 px-1 text-xs">PanelExtension</code> to register sidebar navigation
        items and dashboard widgets:
    </p>

    <x-docs.code lang="php">
use App\Contracts\PanelExtension;
use App\Domain\Shared\DTOs\NavigationItem;

class CrmDashboardPanel implements PanelExtension
{
    public function pages(): array
    {
        return [\Acme\CrmSync\Livewire\CrmContactsPage::class];
    }

    public function navigationItems(): array
    {
        return [
            new NavigationItem(
                label: 'CRM Contacts',
                route: 'crm.contacts',
                icon:  'user-group',
                order: 90,
            ),
        ];
    }

    public function dashboardWidgets(): array
    {
        return [\Acme\CrmSync\Livewire\CrmHealthWidget::class];
    }
}</x-docs.code>

    {{-- HasPluginMeta --}}
    <h3 class="mt-8 text-base font-semibold text-gray-900">HasPluginMeta — namespaced model metadata</h3>
    <p class="mt-2 text-sm text-gray-600">
        Any model that uses the <code class="rounded bg-gray-100 px-1 text-xs">HasPluginMeta</code> trait exposes
        namespaced key-value storage in its <code class="rounded bg-gray-100 px-1 text-xs">meta</code> JSONB column.
        Each plugin's data is isolated under its own ID — plugins cannot overwrite each other's data.
    </p>

    <x-docs.code lang="php">
// Store data on the agent under your plugin's namespace
$agent->setPluginMeta('acme-crm-sync', 'hubspot_contact_id', 'abc123');

// Read it back
$contactId = $agent->getPluginMeta('acme-crm-sync', 'hubspot_contact_id');

// Read all metadata your plugin stored
$all = $agent->allPluginMeta('acme-crm-sync');

// Clean up
$agent->forgetPluginMeta('acme-crm-sync', 'hubspot_contact_id');</x-docs.code>

    <p class="mt-3 text-sm text-gray-600">
        The <code class="rounded bg-gray-100 px-1 text-xs">HasPluginMeta</code> trait is available on
        <strong>Agent</strong>, <strong>Skill</strong>, <strong>Experiment</strong>, and other core models
        that carry a <code class="rounded bg-gray-100 px-1 text-xs">meta</code> JSONB column.
    </p>

    {{-- Installation & enable/disable --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Installing a plugin</h2>

    <h3 class="mt-4 text-base font-semibold text-gray-900">Self-hosted</h3>
    <p class="mt-2 text-sm text-gray-600">
        Install via Composer and run migrations if the plugin ships any:
    </p>

    <x-docs.code lang="bash">
composer require acme/fleet-crm-sync
php artisan migrate</x-docs.code>

    <p class="mt-3 text-sm text-gray-600">
        On the next request, FleetQ auto-discovers the service provider and registers the plugin.
        You'll see it appear in <strong>Settings → Plugins</strong> where you can enable or disable it.
    </p>

    <h3 class="mt-6 text-base font-semibold text-gray-900">Cloud (managed)</h3>
    <p class="mt-2 text-sm text-gray-600">
        On the managed cloud, teams cannot run <code class="rounded bg-gray-100 px-1 text-xs">composer require</code>.
        The platform operator pre-installs and whitelists plugins via
        <code class="rounded bg-gray-100 px-1 text-xs">FLEET_CLOUD_PLUGINS</code>. Each team can then
        <strong>enable or disable</strong> any whitelisted plugin from <strong>Settings → Plugins</strong>
        — no server access required.
    </p>

    <x-docs.callout type="warning">
        Disabling a plugin skips its entire boot phase — event listeners, MCP tools, and routes are not registered.
        Any persisted data (plugin meta, settings) is preserved and restored when re-enabled.
    </x-docs.callout>

    {{-- External providers --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">External plugin providers</h2>
    <p class="mt-2 text-sm text-gray-600">
        For packages that manage their own autoloading outside of your app's <code class="rounded bg-gray-100 px-1 text-xs">composer.json</code>
        (e.g. plugins loaded from a custom path), list their service providers in <code class="rounded bg-gray-100 px-1 text-xs">.env</code>:
    </p>

    <x-docs.code lang="bash" title=".env">
FLEET_EXTERNAL_PLUGIN_PROVIDERS=Acme\CrmSync\CrmSyncServiceProvider,Acme\Analytics\AnalyticsServiceProvider</x-docs.code>

    {{-- Configuration --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Configuration reference</h2>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Variable</th>
                    <th class="py-3 pr-6 text-left font-semibold text-gray-700">Default</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">FLEET_PLUGINS</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-500">true</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Set to <code>false</code> to disable all plugins globally.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">FLEET_CLOUD_PLUGINS</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-500">(empty)</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Cloud only. Comma-separated plugin IDs the platform operator exposes to teams. Teams opt-in/out per plugin.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">FLEET_EXTERNAL_PLUGIN_PROVIDERS</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-500">(empty)</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Comma-separated FQCNs of service providers to register at boot, for packages outside Composer's autoloader.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Self-hosted vs cloud --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Self-hosted vs cloud</h2>
    <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-5">
            <h3 class="font-semibold text-gray-900">Self-hosted</h3>
            <ul class="mt-2 space-y-1.5 text-sm text-gray-600">
                <li>Install any plugin with <code>composer require</code></li>
                <li>Enable / disable globally in Settings → Plugins</li>
                <li><code>plugin_states</code> rows have <code>team_id = NULL</code></li>
                <li>No whitelist required</li>
            </ul>
        </div>
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-5">
            <h3 class="font-semibold text-gray-900">Cloud (managed)</h3>
            <ul class="mt-2 space-y-1.5 text-sm text-gray-600">
                <li>Platform operator installs & whitelists plugins via <code>FLEET_CLOUD_PLUGINS</code></li>
                <li>Each team opts in or out from their plugins page</li>
                <li><code>plugin_states</code> rows are per-team (<code>team_id = uuid</code>)</li>
                <li>Teams cannot install arbitrary packages</li>
            </ul>
        </div>
    </div>
</x-layouts.docs>
