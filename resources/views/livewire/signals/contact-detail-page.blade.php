<div class="space-y-6">
    {{-- Profile card --}}
    <div class="rounded-xl border border-gray-200 bg-white p-6">
        <div class="flex items-start justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900">{{ $contact->display_name ?? 'Unknown contact' }}</h2>
                <p class="mt-0.5 font-mono text-xs text-gray-400">{{ $contact->id }}</p>
            </div>
            <button wire:click="toggleMergeModal"
                class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">
                Merge with another contact
            </button>
        </div>

        <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3">
            @if($contact->phone)
                <div>
                    <dt class="text-xs font-medium text-gray-500">Phone</dt>
                    <dd class="mt-0.5 text-sm text-gray-900">{{ $contact->phone }}</dd>
                </div>
            @endif
            @if($contact->email)
                <div>
                    <dt class="text-xs font-medium text-gray-500">Email</dt>
                    <dd class="mt-0.5 text-sm text-gray-900">{{ $contact->email }}</dd>
                </div>
            @endif
            <div>
                <dt class="text-xs font-medium text-gray-500">First seen</dt>
                <dd class="mt-0.5 text-sm text-gray-900">{{ $contact->created_at->toDateTimeString() }}</dd>
            </div>
        </div>
    </div>

    {{-- Relationship Health Score --}}
    @if($contact->health_scored_at)
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-900">Relationship Health</h3>
                <span @class([
                    'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
                    'bg-green-100 text-green-800' => $contact->health_score >= 0.7,
                    'bg-yellow-100 text-yellow-800' => $contact->health_score >= 0.4 && $contact->health_score < 0.7,
                    'bg-red-100 text-red-800' => $contact->health_score < 0.4,
                ])>
                    {{ $contact->health_score >= 0.7 ? 'Healthy' : ($contact->health_score >= 0.4 ? 'At Risk' : 'Cold') }}
                </span>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <dt class="text-xs font-medium text-gray-500">Overall Score</dt>
                    <dd class="mt-0.5 text-2xl font-semibold text-gray-900">{{ number_format($contact->health_score * 100) }}<span class="text-sm font-normal text-gray-400">%</span></dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-gray-500">Recency</dt>
                    <dd class="mt-0.5 text-sm text-gray-700">{{ number_format($contact->health_recency_score * 100) }}%</dd>
                    <div class="mt-1 h-1.5 w-full rounded-full bg-gray-100"><div class="h-1.5 rounded-full bg-blue-400" style="width: {{ number_format($contact->health_recency_score * 100) }}%"></div></div>
                </div>
                <div>
                    <dt class="text-xs font-medium text-gray-500">Frequency</dt>
                    <dd class="mt-0.5 text-sm text-gray-700">{{ number_format($contact->health_frequency_score * 100) }}%</dd>
                    <div class="mt-1 h-1.5 w-full rounded-full bg-gray-100"><div class="h-1.5 rounded-full bg-purple-400" style="width: {{ number_format($contact->health_frequency_score * 100) }}%"></div></div>
                </div>
                <div>
                    <dt class="text-xs font-medium text-gray-500">Sentiment</dt>
                    <dd class="mt-0.5 text-sm text-gray-700">{{ number_format($contact->health_sentiment_score * 100) }}%</dd>
                    <div class="mt-1 h-1.5 w-full rounded-full bg-gray-100"><div class="h-1.5 rounded-full bg-emerald-400" style="width: {{ number_format($contact->health_sentiment_score * 100) }}%"></div></div>
                </div>
            </div>
            <p class="mt-3 text-xs text-gray-400">Last scored {{ $contact->health_scored_at->diffForHumans() }}</p>
        </div>
    @endif

    {{-- Channels --}}
    <div class="rounded-xl border border-gray-200 bg-white">
        <div class="border-b border-gray-200 px-6 py-4">
            <h3 class="text-sm font-semibold text-gray-900">Linked Channels</h3>
        </div>
        @if($contact->channels->isEmpty())
            <p class="px-6 py-4 text-sm text-gray-500">No channels linked.</p>
        @else
            <ul class="divide-y divide-gray-200">
                @foreach($contact->channels as $ch)
                    @php
                        $colors = ['telegram'=>'bg-blue-100 text-blue-800','whatsapp'=>'bg-green-100 text-green-800','discord'=>'bg-indigo-100 text-indigo-800','slack'=>'bg-yellow-100 text-yellow-800','matrix'=>'bg-teal-100 text-teal-800'];
                        $color = $colors[$ch->channel] ?? 'bg-gray-100 text-gray-800';
                    @endphp
                    <li class="flex items-center justify-between px-6 py-3">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $color }}">
                                {{ ucfirst($ch->channel) }}
                            </span>
                            <div>
                                <p class="text-sm font-medium text-gray-900">{{ $ch->external_username ?? $ch->external_id }}</p>
                                @if($ch->external_username)
                                    <p class="text-xs text-gray-400">{{ $ch->external_id }}</p>
                                @endif
                            </div>
                        </div>
                        <button wire:click="unlinkChannel('{{ $ch->id }}')"
                            wire:confirm="Unlink this channel from the contact?"
                            class="rounded px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50">
                            Unlink
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- Recent signals --}}
    <div class="rounded-xl border border-gray-200 bg-white">
        <div class="border-b border-gray-200 px-6 py-4">
            <h3 class="text-sm font-semibold text-gray-900">Recent Signals</h3>
        </div>
        @if($signals->isEmpty())
            <p class="px-6 py-4 text-sm text-gray-500">No signals received from this contact yet.</p>
        @else
            <ul class="divide-y divide-gray-200">
                @foreach($signals as $signal)
                    <li class="px-6 py-3">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium text-gray-500">{{ $signal->source_type }}</span>
                            <span class="text-xs text-gray-400">{{ $signal->received_at->diffForHumans() }}</span>
                        </div>
                        <p class="mt-0.5 truncate text-sm text-gray-700">
                            {{ $signal->payload['text'] ?? $signal->payload['body'] ?? json_encode($signal->payload) }}
                        </p>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- Merge modal --}}
    @if($showMergeModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h3 class="mb-2 text-lg font-semibold text-gray-900">Merge Contact</h3>
                <p class="mb-4 text-sm text-gray-500">
                    Enter the ID of the contact to merge into this one. All channels from the source will move here and the source will be deleted.
                </p>
                <x-form-input wire:model="mergeTargetId" label="Source contact ID (UUID)" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
                <div class="mt-4 flex justify-end gap-3">
                    <button wire:click="toggleMergeModal" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">Cancel</button>
                    <button wire:click="merge" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm text-white hover:bg-indigo-700">Merge</button>
                </div>
            </div>
        </div>
    @endif
</div>
