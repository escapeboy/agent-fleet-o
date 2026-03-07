<aside class="fixed inset-y-0 left-0 z-50 flex w-64 shrink-0 flex-col bg-(--color-sidebar) text-white
              transition-transform duration-200 ease-in-out
              lg:static lg:z-auto lg:translate-x-0 lg:transition-none"
       :class="sidebarOpen ? 'translate-x-0 shadow-2xl' : '-translate-x-full'"
       x-data="{ current: '{{ request()->routeIs('dashboard') ? 'dashboard' : (request()->routeIs('projects.*') ? 'projects' : (request()->routeIs('experiments.*') ? 'experiments' : (request()->routeIs('workflows.*') ? 'workflows' : (request()->routeIs('approvals.*') ? 'approvals' : (request()->routeIs('health') ? 'health' : (request()->routeIs('settings') ? 'settings' : (request()->routeIs('audit') ? 'audit' : (request()->routeIs('team.*') ? 'team' : 'dashboard')))))))) }}' }">
    {{-- Logo --}}
    <div class="flex h-16 items-center gap-2.5 border-b border-gray-800 px-5">
        <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-primary-600">
            <x-logo-icon class="h-4 w-4 text-white" />
        </div>
        <span class="text-xl font-bold tracking-tight">FleetQ</span>
    </div>

    {{-- Navigation --}}
    <nav class="flex-1 overflow-y-auto space-y-1 px-3 py-4">
        <x-sidebar-link href="{{ route('dashboard') }}" :active="request()->routeIs('dashboard')" icon="chart-bar">
            Dashboard
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('projects.index') }}" :active="request()->routeIs('projects.*')" icon="folder">
            Projects
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('experiments.index') }}" :active="request()->routeIs('experiments.*')" icon="beaker">
            Experiments
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('agents.index') }}" :active="request()->routeIs('agents.*')" icon="cpu-chip">
            Agents
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('crews.index') }}" :active="request()->routeIs('crews.*')" icon="user-group">
            Crews
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('workflows.index') }}" :active="request()->routeIs('workflows.*')" icon="arrow-path">
            Workflows
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('skills.index') }}" :active="request()->routeIs('skills.*')" icon="puzzle-piece">
            Skills
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('tools.index') }}" :active="request()->routeIs('tools.*')" icon="wrench-screwdriver">
            Tools
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('credentials.index') }}" :active="request()->routeIs('credentials.*')" icon="key">
            Credentials
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('integrations.index') }}" :active="request()->routeIs('integrations.*')" icon="puzzle-piece">
            Integrations
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('memory.index') }}" :active="request()->routeIs('memory.*')" icon="circle-stack">
            Memory
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('app.marketplace.index') }}" :active="request()->routeIs('app.marketplace.*')" icon="shopping-bag">
            Marketplace
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('approvals.index') }}" :active="request()->routeIs('approvals.*')" icon="check-circle">
            Approvals
            @php $pendingCount = \App\Domain\Approval\Models\ApprovalRequest::where('status', 'pending')->count(); @endphp
            @if($pendingCount > 0)
                <span class="ml-auto rounded-full bg-red-500 px-2 py-0.5 text-xs font-medium">{{ $pendingCount }}</span>
            @endif
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('signals.connectors') }}" :active="request()->routeIs('signals.connectors')" icon="plug">
            Signal Sources
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('email.themes.index') }}" :active="request()->routeIs('email.*')" icon="envelope">
            Email Themes
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('health') }}" :active="request()->routeIs('health')" icon="heart">
            Health
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('audit') }}" :active="request()->routeIs('audit')" icon="document-text">
            Audit Log
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('settings') }}" :active="request()->routeIs('settings')" icon="cog">
            Settings
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('team.settings') }}" :active="request()->routeIs('team.*')" icon="user-group">
            API Keys
        </x-sidebar-link>
    </nav>

    {{-- Footer --}}
    <div class="border-t border-gray-800 px-4 py-3">
        @php
            $hasNewChanges = app(\App\Domain\System\Services\ChangelogParser::class)->hasNewEntries(auth()->user()?->changelog_seen_at);
        @endphp
        <div class="flex items-center justify-between text-xs text-gray-500">
            <span>FleetQ v{{ config('app.version') }}</span>
            <a href="{{ route('changelog') }}"
               class="flex items-center gap-1.5 text-gray-400 hover:text-white transition-colors"
               title="What's New">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
                </svg>
                <span>What's New</span>
                @if($hasNewChanges)
                    <span class="h-2 w-2 rounded-full bg-primary-400"></span>
                @endif
            </a>
        </div>
    </div>
</aside>
