@php
    $activeRailGroup = match(true) {
        request()->routeIs('dashboard', 'projects.*', 'experiments.*', 'agents.*', 'crews.*', 'approvals.*', 'chatbots.*') => 'fleet',
        request()->routeIs('workflows.*', 'skills.*', 'memory.*', 'knowledge.*', 'knowledge-graph.*', 'evaluation.*', 'evaluations.*', 'triggers.*', 'evolution.*', 'websites.*') => 'build',
        request()->routeIs('signals.*', 'bug-reports.*', 'contacts.*', 'email.*', 'outbound.*', 'health', 'audit', 'metrics.*') => 'monitor',
        request()->routeIs('app.marketplace.*', 'marketplace.*', 'plugins', 'telegram.*') => 'marketplace',
        request()->routeIs('tools.*', 'credentials.*', 'integrations.*', 'git-repositories.*', 'team.*', 'settings', 'profile', 'notifications.*') => 'settings',
        default => null,
    };
    $pendingCount = \App\Domain\Approval\Models\ApprovalRequest::where('status', 'pending')->count();
    $pendingEvolutionCount = Route::has('evolution.index')
        ? \App\Domain\Evolution\Models\EvolutionProposal::where('status', 'pending')->count()
        : 0;
    $monitorAlert = $pendingCount > 0;
@endphp

{{-- Click-outside overlay (desktop only — closes flyout when clicking main content) --}}
<div x-show="nav !== null"
     @click="nav = null"
     class="fixed inset-0 z-[35] hidden lg:block"
     style="display: none;"></div>

{{-- ═══════════════════════════════ Icon Rail (desktop) ═══════════════════════════════ --}}
<aside class="relative z-50 hidden w-[52px] shrink-0 flex-col items-center border-r
              border-(--color-sidebar-border) bg-(--color-sidebar) py-3 lg:flex">

    {{-- Logo --}}
    <a href="{{ route('dashboard') }}"
       class="mb-3 flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-primary-600 hover:bg-primary-700 transition-colors">
        <x-logo-icon class="h-4 w-4 text-white" />
    </a>

    {{-- Rail icon buttons --}}
    <nav class="flex flex-1 flex-col items-center gap-0.5 px-1.5 w-full">

        {{-- Fleet --}}
        <button @click="nav = nav === 'fleet' ? null : 'fleet'"
                :class="nav === 'fleet' ? 'bg-(--color-sidebar-hover) text-white' : ''"
                class="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors
                       {{ $activeRailGroup === 'fleet' ? 'text-white' : 'text-gray-400 hover:bg-(--color-sidebar-hover)/60 hover:text-white' }}"
                title="Fleet" aria-label="Fleet">
            <i class="fas fa-house h-5 w-5"></i>
            @if($pendingCount > 0)
                <span class="absolute right-1.5 top-1.5 h-[7px] w-[7px] rounded-full bg-red-500 ring-1 ring-(--color-sidebar)"></span>
            @endif
        </button>

        {{-- Build --}}
        <button @click="nav = nav === 'build' ? null : 'build'"
                :class="nav === 'build' ? 'bg-(--color-sidebar-hover) text-white' : ''"
                class="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors
                       {{ $activeRailGroup === 'build' ? 'text-white' : 'text-gray-400 hover:bg-(--color-sidebar-hover)/60 hover:text-white' }}"
                title="Build" aria-label="Build">
            <i class="fas fa-wrench h-5 w-5"></i>
        </button>

        {{-- Separator --}}
        <div class="my-1 h-px w-6 shrink-0 bg-gray-700"></div>

        {{-- Monitor --}}
        <button @click="nav = nav === 'monitor' ? null : 'monitor'"
                :class="nav === 'monitor' ? 'bg-(--color-sidebar-hover) text-white' : ''"
                class="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors
                       {{ $activeRailGroup === 'monitor' ? 'text-white' : 'text-gray-400 hover:bg-(--color-sidebar-hover)/60 hover:text-white' }}"
                title="Monitor" aria-label="Monitor">
            <i class="fas fa-tower-cell h-5 w-5"></i>
            @if($monitorAlert)
                <span class="absolute right-1.5 top-1.5 h-[7px] w-[7px] rounded-full bg-red-500 ring-1 ring-(--color-sidebar)"></span>
            @endif
        </button>

        {{-- Marketplace --}}
        <button @click="nav = nav === 'marketplace' ? null : 'marketplace'"
                :class="nav === 'marketplace' ? 'bg-(--color-sidebar-hover) text-white' : ''"
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors
                       {{ $activeRailGroup === 'marketplace' ? 'text-white' : 'text-gray-400 hover:bg-(--color-sidebar-hover)/60 hover:text-white' }}"
                title="Marketplace" aria-label="Marketplace">
            <i class="fas fa-bag-shopping h-5 w-5"></i>
        </button>

        {{-- Settings --}}
        <button @click="nav = nav === 'settings' ? null : 'settings'"
                :class="nav === 'settings' ? 'bg-(--color-sidebar-hover) text-white' : ''"
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors
                       {{ $activeRailGroup === 'settings' ? 'text-white' : 'text-gray-400 hover:bg-(--color-sidebar-hover)/60 hover:text-white' }}"
                title="Settings" aria-label="Settings">
            <i class="fas fa-gear h-5 w-5"></i>
        </button>

    </nav>

    {{-- User avatar (bottom of rail) --}}
    <div class="mt-auto px-1.5 pt-1">
        <div class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-900/40 ring-1 ring-primary-700/50"
             title="{{ auth()->user()?->name }}">
            <span class="text-xs font-semibold text-primary-300 uppercase select-none">
                {{ mb_strtoupper(mb_substr(auth()->user()?->name ?? 'U', 0, 2)) }}
            </span>
        </div>
    </div>
</aside>

{{-- ═══════════════════════════════ Flyout Panel (desktop) ════════════════════════════ --}}
<div class="relative z-40 hidden shrink-0 overflow-hidden border-r border-(--color-sidebar-border) bg-(--color-sidebar) lg:block"
     style="width: 0; transition: width 180ms ease;"
     :style="{ width: nav !== null ? '200px' : '0px' }">

    <div class="h-full w-[200px]">

        {{-- Fleet panel --}}
        <div x-show="nav === 'fleet'" class="flex h-full flex-col py-3" style="display: none;">
            <p class="mb-1 px-4 text-xs font-semibold uppercase tracking-wider text-gray-500">Fleet</p>
            <nav class="flex-1 overflow-y-auto px-2">
                <x-sidebar-link href="{{ route('dashboard') }}" :active="request()->routeIs('dashboard')" icon="chart-bar">Dashboard</x-sidebar-link>
                <x-sidebar-link href="{{ route('projects.index') }}" :active="request()->routeIs('projects.*')" icon="folder">Projects</x-sidebar-link>
                <x-sidebar-link href="{{ route('experiments.index') }}" :active="request()->routeIs('experiments.*')" icon="play">Runs</x-sidebar-link>
                <x-sidebar-link href="{{ route('agents.index') }}" :active="request()->routeIs('agents.*')" icon="cpu-chip">Agents</x-sidebar-link>
                <x-sidebar-link href="{{ route('crews.index') }}" :active="request()->routeIs('crews.*')" icon="user-group">Crews</x-sidebar-link>
                <x-sidebar-link href="{{ route('approvals.index') }}" :active="request()->routeIs('approvals.*')" icon="check-circle">
                    Approvals
                    @if($pendingCount > 0)
                        <span class="ml-auto rounded-full bg-red-500 px-1.5 py-0.5 text-xs font-medium">{{ $pendingCount }}</span>
                    @endif
                </x-sidebar-link>
                @if(auth()->user()?->currentTeam?->settings['chatbot_enabled'] ?? false)
                    <x-sidebar-link href="{{ route('chatbots.index') }}" :active="request()->routeIs('chatbots.*')" icon="chat-bubble-left-right">Chatbots</x-sidebar-link>
                @endif
            </nav>
        </div>

        {{-- Build panel --}}
        <div x-show="nav === 'build'" class="flex h-full flex-col py-3" style="display: none;">
            <p class="mb-1 px-4 text-xs font-semibold uppercase tracking-wider text-gray-500">Build</p>
            <nav class="flex-1 overflow-y-auto px-2">
                <x-sidebar-link href="{{ route('workflows.index') }}" :active="request()->routeIs('workflows.*')" icon="arrow-path">Workflows</x-sidebar-link>
                <x-sidebar-link href="{{ route('websites.index') }}" :active="request()->routeIs('websites.*')" icon="globe-alt">Websites</x-sidebar-link>
                <x-sidebar-link href="{{ route('skills.index') }}" :active="request()->routeIs('skills.*')" icon="puzzle-piece">Skills</x-sidebar-link>
                <x-sidebar-link href="{{ route('memory.index') }}" :active="request()->routeIs('memory.*') && !request()->routeIs('knowledge.*')" icon="circle-stack">Memory</x-sidebar-link>
                <x-sidebar-link href="{{ route('knowledge.index') }}" :active="request()->routeIs('knowledge.*') && !request()->routeIs('knowledge-graph.*')" icon="book-open">Knowledge</x-sidebar-link>
                <x-sidebar-link href="{{ route('knowledge-graph.index') }}" :active="request()->routeIs('knowledge-graph.*')" icon="share">Knowledge Graph</x-sidebar-link>
                <x-sidebar-link href="{{ route('evaluation.index') }}" :active="request()->routeIs('evaluation.index')" icon="scale">Evaluation</x-sidebar-link>
                <x-sidebar-link href="{{ route('evaluations.index') }}" :active="request()->routeIs('evaluations.*')" icon="beaker">Flow Evals</x-sidebar-link>
                <x-sidebar-link href="{{ route('triggers.index') }}" :active="request()->routeIs('triggers.*')" icon="bolt">Triggers</x-sidebar-link>
                @if(Route::has('evolution.index'))
                    <x-sidebar-link href="{{ route('evolution.index') }}" :active="request()->routeIs('evolution.*')" icon="sparkles">
                        Evolution
                        @if($pendingEvolutionCount > 0)
                            <span class="ml-auto rounded-full bg-amber-500 px-1.5 py-0.5 text-xs font-medium">{{ $pendingEvolutionCount }}</span>
                        @endif
                    </x-sidebar-link>
                @endif
            </nav>
        </div>

        {{-- Monitor panel --}}
        <div x-show="nav === 'monitor'" class="flex h-full flex-col py-3" style="display: none;">
            <p class="mb-1 px-4 text-xs font-semibold uppercase tracking-wider text-gray-500">Monitor</p>
            <nav class="flex-1 overflow-y-auto px-2">
                <x-sidebar-link href="{{ route('signals.index') }}" :active="request()->routeIs('signals.index')" icon="bolt">Signals</x-sidebar-link>
                <x-sidebar-link href="{{ route('signals.connectors') }}" :active="request()->routeIs('signals.connectors')" icon="plug">Signal Sources</x-sidebar-link>
                <x-sidebar-link href="{{ route('signals.subscriptions') }}" :active="request()->routeIs('signals.subscriptions')" icon="bell">Subscriptions</x-sidebar-link>
                <x-sidebar-link href="{{ route('signals.entities') }}" :active="request()->routeIs('signals.entities')" icon="squares-2x2">Entities</x-sidebar-link>
                <x-sidebar-link href="{{ route('signals.bindings') }}" :active="request()->routeIs('signals.bindings')" icon="link">Bindings</x-sidebar-link>
                <x-sidebar-link href="{{ route('bug-reports.index') }}" :active="request()->routeIs('bug-reports.*')" icon="bug-ant">Bug Reports</x-sidebar-link>
                <x-sidebar-link href="{{ route('contacts.index') }}" :active="request()->routeIs('contacts.*')" icon="identification">Contacts</x-sidebar-link>
                <x-sidebar-link href="{{ route('email.templates.index') }}" :active="request()->routeIs('email.templates.*')" icon="document-text">Email Templates</x-sidebar-link>
                <x-sidebar-link href="{{ route('email.themes.index') }}" :active="request()->routeIs('email.themes.*')" icon="envelope">Email Themes</x-sidebar-link>
                <x-sidebar-link href="{{ route('outbound.email') }}" :active="request()->routeIs('outbound.email')" icon="envelope">Email Delivery</x-sidebar-link>
                <x-sidebar-link href="{{ route('outbound.webhooks') }}" :active="request()->routeIs('outbound.webhooks')" icon="link">Webhooks</x-sidebar-link>
                <x-sidebar-link href="{{ route('outbound.notifications') }}" :active="request()->routeIs('outbound.notifications')" icon="bell">Notifications</x-sidebar-link>
                <x-sidebar-link href="{{ route('outbound.whatsapp') }}" :active="request()->routeIs('outbound.whatsapp')" icon="chat-bubble-left-right">WhatsApp</x-sidebar-link>
                <x-sidebar-link href="{{ route('health') }}" :active="request()->routeIs('health')" icon="heart">Health</x-sidebar-link>
                <x-sidebar-link href="{{ route('audit') }}" :active="request()->routeIs('audit')" icon="document-text">Audit Log</x-sidebar-link>
                <x-sidebar-link href="{{ route('metrics.models') }}" :active="request()->routeIs('metrics.models')" icon="scale">Model Comparison</x-sidebar-link>
                <x-sidebar-link href="{{ route('metrics.ai-routing') }}" :active="request()->routeIs('metrics.ai-routing')" icon="arrow-path">AI Routing</x-sidebar-link>
            </nav>
        </div>

        {{-- Marketplace panel --}}
        <div x-show="nav === 'marketplace'" class="flex h-full flex-col py-3" style="display: none;">
            <p class="mb-1 px-4 text-xs font-semibold uppercase tracking-wider text-gray-500">Marketplace</p>
            <nav class="flex-1 overflow-y-auto px-2">
                <x-sidebar-link href="{{ route('app.marketplace.index') }}" :active="request()->routeIs('app.marketplace.*') && !request()->routeIs('app.marketplace.publish')" icon="shopping-bag">Browse</x-sidebar-link>
                <x-sidebar-link href="{{ route('app.marketplace.publish') }}" :active="request()->routeIs('app.marketplace.publish')" icon="paper-airplane">Publish</x-sidebar-link>
                @if(config('plugins.enabled'))
                    <x-sidebar-link href="{{ route('plugins') }}" :active="request()->routeIs('plugins')" icon="puzzle-piece">Plugins</x-sidebar-link>
                @endif
                @if(Route::has('telegram.bots'))
                    <x-sidebar-link href="{{ route('telegram.bots') }}" :active="request()->routeIs('telegram.*')" icon="chat-bubble-left-right">Telegram Bots</x-sidebar-link>
                @endif
            </nav>
        </div>

        {{-- Settings panel --}}
        <div x-show="nav === 'settings'" class="flex h-full flex-col py-3" style="display: none;">
            <p class="mb-1 px-4 text-xs font-semibold uppercase tracking-wider text-gray-500">Settings</p>
            <nav class="flex-1 overflow-y-auto px-2">
                <x-sidebar-link href="{{ route('tools.index') }}" :active="request()->routeIs('tools.*')" icon="wrench-screwdriver">Tools</x-sidebar-link>
                <x-sidebar-link href="{{ route('credentials.index') }}" :active="request()->routeIs('credentials.*')" icon="key">Credentials</x-sidebar-link>
                <x-sidebar-link href="{{ route('integrations.index') }}" :active="request()->routeIs('integrations.*')" icon="link">Integrations</x-sidebar-link>
                <x-sidebar-link href="{{ route('git-repositories.index') }}" :active="request()->routeIs('git-repositories.*')" icon="code-branch">Git Repos</x-sidebar-link>
                <x-sidebar-link href="{{ route('team.settings') }}" :active="request()->routeIs('team.*')" icon="user-group">Team</x-sidebar-link>
                <x-sidebar-link href="{{ route('settings') }}" :active="request()->routeIs('settings')" icon="cog">Global Settings</x-sidebar-link>
                @if(Route::has('profile'))
                    <x-sidebar-link href="{{ route('profile') }}" :active="request()->routeIs('profile')" icon="user-circle">Profile</x-sidebar-link>
                @endif
                @if(Route::has('notifications.index'))
                    <x-sidebar-link href="{{ route('notifications.index') }}" :active="request()->routeIs('notifications.*')" icon="bell">Notifications</x-sidebar-link>
                @endif
                {{-- Plugin-contributed navigation items --}}
                @php $pluginNavItems = app(\App\Domain\Shared\Services\NavigationRegistry::class)->items(); @endphp
                @foreach($pluginNavItems as $navItem)
                    @if(!$navItem->permission || \Illuminate\Support\Facades\Gate::check($navItem->permission))
                        <x-sidebar-link href="{{ $navItem->route }}" :active="request()->is(ltrim($navItem->route, '/').'*')" icon="{{ $navItem->icon ?? 'puzzle-piece' }}">
                            {{ $navItem->label }}
                            @if($navItem->badge)
                                <span class="ml-auto rounded-full bg-primary-500 px-1.5 py-0.5 text-xs font-medium">{{ $navItem->badge }}</span>
                            @endif
                        </x-sidebar-link>
                    @endif
                @endforeach
            </nav>
        </div>

    </div>
</div>

{{-- ═══════════════════════════════ Mobile Bottom Bar ════════════════════════════════ --}}
<nav class="fixed inset-x-0 bottom-0 z-50 flex h-14 items-center justify-around border-t
            border-(--color-sidebar-border) bg-(--color-sidebar) lg:hidden">

    <a href="{{ route('dashboard') }}" wire:navigate
       class="relative flex flex-col items-center gap-0.5 px-3 py-1.5 transition-colors
              {{ $activeRailGroup === 'fleet' ? 'text-white' : 'text-gray-400' }}">
        <i class="fas fa-house h-5 w-5"></i>
        <span class="text-[10px] font-medium">Fleet</span>
        @if($pendingCount > 0)
            <span class="absolute right-2 top-1 h-[7px] w-[7px] rounded-full bg-red-500"></span>
        @endif
    </a>

    <a href="{{ route('workflows.index') }}" wire:navigate
       class="flex flex-col items-center gap-0.5 px-3 py-1.5 transition-colors
              {{ $activeRailGroup === 'build' ? 'text-white' : 'text-gray-400' }}">
        <i class="fas fa-wrench h-5 w-5"></i>
        <span class="text-[10px] font-medium">Build</span>
    </a>

    <a href="{{ route('signals.connectors') }}" wire:navigate
       class="relative flex flex-col items-center gap-0.5 px-3 py-1.5 transition-colors
              {{ $activeRailGroup === 'monitor' ? 'text-white' : 'text-gray-400' }}">
        <i class="fas fa-tower-cell h-5 w-5"></i>
        <span class="text-[10px] font-medium">Monitor</span>
        @if($monitorAlert)
            <span class="absolute right-2 top-1 h-[7px] w-[7px] rounded-full bg-red-500"></span>
        @endif
    </a>

    <a href="{{ route('app.marketplace.index') }}" wire:navigate
       class="flex flex-col items-center gap-0.5 px-3 py-1.5 transition-colors
              {{ $activeRailGroup === 'marketplace' ? 'text-white' : 'text-gray-400' }}">
        <i class="fas fa-bag-shopping h-5 w-5"></i>
        <span class="text-[10px] font-medium">Market</span>
    </a>

    <a href="{{ route('team.settings') }}" wire:navigate
       class="flex flex-col items-center gap-0.5 px-3 py-1.5 transition-colors
              {{ $activeRailGroup === 'settings' ? 'text-white' : 'text-gray-400' }}">
        <i class="fas fa-gear h-5 w-5"></i>
        <span class="text-[10px] font-medium">Settings</span>
    </a>

</nav>
