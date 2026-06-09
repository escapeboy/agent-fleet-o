<div>
    {{-- Intro --}}
    <div class="mb-6 rounded-xl border border-gray-200 bg-white p-4">
        <div class="flex items-start gap-3">
            <div class="mt-0.5 flex-shrink-0">
                <i class="fa-solid fa-magnifying-glass-chart text-lg text-amber-600"></i>
            </div>
            <div class="flex-1">
                <h4 class="text-sm font-semibold text-gray-900">Secret Scan Findings</h4>
                <p class="mt-0.5 text-sm text-gray-600">
                    Automated scans of agent, skill, and workflow free-text fields for accidentally embedded
                    API keys and secrets. Review each finding, re-scan after editing the source, or acknowledge
                    once resolved.
                </p>
            </div>
            <div class="text-right">
                <span class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-sm font-semibold text-amber-800">
                    {{ $openCount }} open
                </span>
            </div>
        </div>
    </div>

    {{-- Toolbar --}}
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <x-form-select wire:model.live="subjectTypeFilter">
            <option value="">All Sources</option>
            @foreach($subjectTypes as $type)
                <option value="{{ $type }}">{{ \Illuminate\Support\Str::headline($type) }}</option>
            @endforeach
        </x-form-select>

        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" wire:model.live="showAcknowledged"
                class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
            Show acknowledged
        </label>

        <div x-data="{ msg: '' }"
            x-on:scan-rescan-queued.window="msg = 'Re-scan queued.'; setTimeout(() => msg = '', 3000)"
            x-on:scan-rescan-empty.window="msg = 'No scannable text remains — finding may be stale.'; setTimeout(() => msg = '', 4000)"
            x-on:scan-rescan-missing.window="msg = 'Source record no longer exists.'; setTimeout(() => msg = '', 4000)"
            x-on:scan-finding-acknowledged.window="msg = 'Finding acknowledged.'; setTimeout(() => msg = '', 3000)"
            class="text-sm font-medium text-green-700" x-text="msg"></div>
    </div>

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Pattern</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Source</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Field</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Detected</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @forelse($findings as $finding)
                        @php
                            $props = $finding->properties ?? [];
                            $acknowledged = ! empty($props['acknowledged_at']);
                        @endphp
                        <tr class="{{ $acknowledged ? 'opacity-60' : '' }}">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2">
                                    <i class="fa-solid fa-triangle-exclamation text-amber-500"></i>
                                    <span class="text-sm font-medium text-gray-900">{{ $props['pattern_name'] ?? 'Secret' }}</span>
                                </div>
                                <div class="mt-0.5 font-mono text-xs text-gray-400">{{ $props['pattern_id'] ?? '' }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
                                    {{ \Illuminate\Support\Str::headline(\Illuminate\Support\Str::afterLast($finding->subject_type, '\\')) }}
                                </span>
                                <div class="mt-0.5 font-mono text-xs text-gray-400">{{ \Illuminate\Support\Str::limit($finding->subject_id, 12, '…') }}</div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-700">
                                <code class="rounded bg-gray-50 px-1.5 py-0.5 text-xs">{{ $props['field'] ?? '—' }}</code>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">
                                {{ $finding->created_at?->diffForHumans() ?? '—' }}
                            </td>
                            <td class="px-6 py-4">
                                @if($acknowledged)
                                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600">
                                        <i class="fa-solid fa-check mr-1"></i> Acknowledged
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-700">
                                        Open
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right text-sm">
                                <div class="flex items-center justify-end gap-3">
                                    <button wire:click="rescan('{{ $finding->id }}')"
                                        wire:loading.attr="disabled"
                                        class="font-medium text-primary-600 hover:text-primary-800">
                                        Re-scan
                                    </button>
                                    @unless($acknowledged)
                                        <button wire:click="acknowledge('{{ $finding->id }}')"
                                            class="font-medium text-gray-500 hover:text-gray-700">
                                            Acknowledge
                                        </button>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-sm text-gray-500">
                                <i class="fa-solid fa-shield-halved mb-2 block text-2xl text-green-500"></i>
                                No secret-scan findings. Free-text fields are clean.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $findings->links() }}
    </div>
</div>
