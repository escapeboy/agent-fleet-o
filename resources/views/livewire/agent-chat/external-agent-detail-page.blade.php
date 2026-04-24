<div class="max-w-5xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
    <div class="mb-4">
        <a href="{{ route('external-agents.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← All external agents</a>
    </div>

    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">{{ $externalAgent->name }}</h1>
            <div class="flex items-center gap-2 mt-1">
                <span class="text-xs font-mono text-gray-500">{{ $externalAgent->slug }}</span>
                <span @class([
                    'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium',
                    'bg-green-100 text-green-800' => $externalAgent->status->value === 'active',
                    'bg-yellow-100 text-yellow-800' => $externalAgent->status->value === 'paused',
                    'bg-red-100 text-red-800' => in_array($externalAgent->status->value, ['unreachable', 'disabled']),
                ])>{{ $externalAgent->status->value }}</span>
            </div>
        </div>
        <div class="flex gap-2">
            <button wire:click="ping"
                    class="px-3 py-1.5 text-sm bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                Ping
            </button>
            <button wire:click="refreshManifest"
                    class="px-3 py-1.5 text-sm bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                Refresh manifest
            </button>
            <button wire:click="disable" wire:confirm="Disable this external agent? Pending calls will fail."
                    class="px-3 py-1.5 text-sm bg-red-50 border border-red-300 text-red-700 rounded-lg hover:bg-red-100">
                Disable
            </button>
        </div>
    </div>

    @if (session('success'))
        <div class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-800">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">{{ session('error') }}</div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <div class="bg-white shadow rounded-lg p-4">
            <h3 class="text-sm font-medium text-gray-700 mb-2">Endpoint</h3>
            <p class="text-xs font-mono text-gray-600 break-all">{{ $externalAgent->endpoint_url }}</p>
        </div>
        <div class="bg-white shadow rounded-lg p-4">
            <h3 class="text-sm font-medium text-gray-700 mb-2">Protocol</h3>
            <p class="text-xs font-mono text-gray-600">{{ $externalAgent->protocol_version }}</p>
            @if ($externalAgent->manifest_fetched_at)
                <p class="text-xs text-gray-500 mt-1">Manifest fetched {{ $externalAgent->manifest_fetched_at->diffForHumans() }}</p>
            @endif
        </div>
    </div>

    @if ($externalAgent->capabilities)
        <div class="bg-white shadow rounded-lg p-4 mb-6">
            <h3 class="text-sm font-medium text-gray-700 mb-2">Capabilities</h3>
            <pre class="text-xs font-mono text-gray-600 bg-gray-50 rounded p-3 overflow-x-auto">{{ json_encode($externalAgent->capabilities, JSON_PRETTY_PRINT) }}</pre>
        </div>
    @endif

    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="p-4 border-b border-gray-200">
            <h2 class="text-lg font-medium text-gray-900">Recent protocol messages</h2>
            <p class="text-xs text-gray-500 mt-1">Last 50 messages exchanged with this agent</p>
        </div>

        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Direction</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">When</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($recentMessages as $msg)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 text-xs">
                            <span @class([
                                'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
                                'bg-blue-100 text-blue-800' => $msg->direction->value === 'inbound',
                                'bg-gray-100 text-gray-800' => $msg->direction->value === 'outbound',
                            ])>{{ $msg->direction->value }}</span>
                        </td>
                        <td class="px-4 py-2 text-xs font-mono">{{ $msg->message_type->value }}</td>
                        <td class="px-4 py-2 text-xs">{{ $msg->status->value }}</td>
                        <td class="px-4 py-2 text-xs text-gray-500">{{ $msg->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-6 text-center text-sm text-gray-500">No messages exchanged yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
