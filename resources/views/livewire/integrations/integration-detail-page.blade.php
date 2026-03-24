<div class="space-y-6">
    {{-- Flash Messages --}}
    @if(session()->has('message'))
        <div class="rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('message') }}</div>
    @endif
    @if(session()->has('error'))
        <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    {{-- Re-authorization Warning --}}
    @if($integration->status === \App\Domain\Integration\Enums\IntegrationStatus::RequiresReauth)
        <div class="rounded-lg border border-orange-200 bg-orange-50 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="font-medium text-orange-800">Re-authorization Required</p>
                    <p class="mt-0.5 text-sm text-orange-700">The OAuth2 token for this integration has expired or been revoked. Please re-authorize to restore access.</p>
                </div>
                <button wire:click="reconnect"
                        class="ml-4 shrink-0 rounded-lg bg-orange-600 px-4 py-2 text-sm font-medium text-white hover:bg-orange-700">
                    Reconnect →
                </button>
            </div>
        </div>
    @endif

    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <a href="{{ route('integrations.index') }}" class="text-sm text-primary-600 hover:underline">← Integrations</a>
            <h1 class="mt-1 text-2xl font-semibold text-gray-900">{{ $integration->name }}</h1>
        </div>
        <div class="flex gap-3">
            <button wire:click="ping"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Ping
            </button>
            <button wire:click="disconnect" wire:confirm="Disconnect this integration?"
                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                Disconnect
            </button>
        </div>
    </div>

    {{-- Status Card --}}
    <div class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="mb-4 text-lg font-semibold text-gray-900">Status</h2>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Driver</p>
                <p class="mt-1 font-medium text-gray-900">{{ $driver->label() }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Status</p>
                <span class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs {{ $integration->status->color() }}">
                    {{ $integration->status->label() }}
                </span>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Last Ping</p>
                <p class="mt-1 text-sm text-gray-900">
                    {{ $integration->last_pinged_at ? $integration->last_pinged_at->diffForHumans() : 'Never' }}
                </p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Error Count</p>
                <p class="mt-1 text-sm {{ $integration->error_count > 0 ? 'text-red-600 font-medium' : 'text-gray-900' }}">
                    {{ $integration->error_count }}
                </p>
            </div>
        </div>
        @if($integration->last_ping_message)
            <p class="mt-4 text-sm text-gray-500">Last message: {{ $integration->last_ping_message }}</p>
        @endif
    </div>

    {{-- Webhook Routes --}}
    @if($webhookRoutes->isNotEmpty())
        <div class="rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="mb-4 text-lg font-semibold text-gray-900">Webhook Endpoints</h2>
            <div class="space-y-2">
                @foreach($webhookRoutes as $route)
                    <div class="rounded-lg bg-gray-50 p-3">
                        <p class="text-xs font-medium text-gray-500">POST</p>
                        <code class="text-sm text-gray-900">{{ url('/api/integrations/webhook/'.$route->slug) }}</code>
                        @if($route->subscribed_events)
                            <p class="mt-1 text-xs text-gray-500">Events: {{ implode(', ', $route->subscribed_events) }}</p>
                        @endif
                        <span class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs {{ $route->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                            {{ $route->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Available Triggers --}}
    @if(!empty($triggers))
        <div class="rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="mb-4 text-lg font-semibold text-gray-900">Triggers</h2>
            <div class="space-y-2">
                @foreach($triggers as $trigger)
                    <div class="rounded-lg border border-gray-100 p-3">
                        <p class="text-sm font-medium text-gray-900">{{ $trigger->label }}</p>
                        <p class="text-xs text-gray-500">{{ $trigger->description }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Available Actions --}}
    @if(!empty($actions))
        <div class="rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="mb-4 text-lg font-semibold text-gray-900">Actions</h2>
            <div class="space-y-2">
                @foreach($actions as $action)
                    <div class="rounded-lg border border-gray-100 p-3">
                        <p class="text-sm font-medium text-gray-900">{{ $action->label }}</p>
                        <p class="text-xs text-gray-500">{{ $action->description }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
