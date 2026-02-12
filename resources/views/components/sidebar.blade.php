<aside class="flex w-64 flex-col bg-(--color-sidebar) text-white" x-data="{ current: '{{ request()->routeIs('dashboard') ? 'dashboard' : (request()->routeIs('projects.*') ? 'projects' : (request()->routeIs('workflows.*') ? 'workflows' : (request()->routeIs('approvals.*') ? 'approvals' : (request()->routeIs('health') ? 'health' : (request()->routeIs('settings') ? 'settings' : (request()->routeIs('audit') ? 'audit' : (request()->routeIs('team.*') ? 'team' : 'dashboard'))))))) }}' }">
    {{-- Logo --}}
    <div class="flex h-16 items-center border-b border-gray-800 px-6">
        <span class="text-xl font-bold tracking-tight">Agent Fleet</span>
    </div>

    {{-- Navigation --}}
    <nav class="flex-1 space-y-1 px-3 py-4">
        <x-sidebar-link href="{{ route('dashboard') }}" :active="request()->routeIs('dashboard')" icon="chart-bar">
            Dashboard
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('skills.index') }}" :active="request()->routeIs('skills.*')" icon="puzzle-piece">
            Skills
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('agents.index') }}" :active="request()->routeIs('agents.*')" icon="cpu-chip">
            Agents
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('crews.index') }}" :active="request()->routeIs('crews.*')" icon="user-group">
            Crews
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('projects.index') }}" :active="request()->routeIs('projects.*')" icon="folder">
            Projects
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('workflows.index') }}" :active="request()->routeIs('workflows.*')" icon="arrow-path">
            Workflows
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('marketplace.index') }}" :active="request()->routeIs('marketplace.*')" icon="shopping-bag">
            Marketplace
        </x-sidebar-link>

        <x-sidebar-link href="{{ route('approvals.index') }}" :active="request()->routeIs('approvals.*')" icon="check-circle">
            Approvals
            @php $pendingCount = \App\Domain\Approval\Models\ApprovalRequest::where('status', 'pending')->count(); @endphp
            @if($pendingCount > 0)
                <span class="ml-auto rounded-full bg-red-500 px-2 py-0.5 text-xs font-medium">{{ $pendingCount }}</span>
            @endif
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
    <div class="border-t border-gray-800 p-4">
        <div class="text-xs text-gray-500">
            Agent Fleet v1.0
        </div>
    </div>
</aside>
