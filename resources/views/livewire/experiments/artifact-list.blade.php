<div>
    @if($artifacts->isEmpty() && $failedTasks->isEmpty())
        {{-- Empty State --}}
        <div class="rounded-lg border border-gray-200 bg-white p-8 text-center">
            <svg class="mx-auto h-10 w-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>
            </svg>
            <p class="mt-3 text-sm text-gray-400">No artifacts built yet.</p>
            <p class="text-xs text-gray-400">Artifacts will appear once the building stage completes.</p>
        </div>
    @else
        <div class="flex gap-4" style="min-height: 520px;" x-data="artifactPreview(@js($selectedArtifactId))">
            {{-- Left Sidebar: Artifact List --}}
            <div class="w-60 flex-shrink-0 space-y-1.5 overflow-y-auto rounded-lg border border-gray-200 bg-white p-2">
                <p class="px-2 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">Artifacts ({{ $artifacts->count() }})</p>

                @foreach($artifacts as $artifact)
                    @php
                        $isSelected = $selectedArtifactId === $artifact->id;
                        $category = \App\Domain\Experiment\Services\ArtifactContentResolver::category($artifact->type);
                        $badgeColor = match($category) {
                            'html' => 'bg-blue-100 text-blue-700',
                            'markdown' => 'bg-emerald-100 text-emerald-700',
                            'json' => 'bg-amber-100 text-amber-700',
                            default => 'bg-gray-100 text-gray-600',
                        };
                    @endphp
                    <button
                        wire:click="selectArtifact('{{ $artifact->id }}')"
                        class="flex w-full items-start gap-2 rounded-md px-2.5 py-2 text-left transition
                            {{ $isSelected ? 'bg-primary-50 ring-1 ring-primary-300' : 'hover:bg-gray-50' }}"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium {{ $isSelected ? 'text-primary-700' : 'text-gray-900' }}">
                                {{ $artifact->name }}
                            </p>
                            <div class="mt-0.5 flex items-center gap-1.5">
                                <span class="inline-flex rounded px-1.5 py-0.5 text-[10px] font-medium {{ $badgeColor }}">
                                    {{ $category }}
                                </span>
                                <span class="text-[10px] text-gray-400">v{{ $artifact->current_version }}</span>
                                @if($artifact->versions_count > 1)
                                    <span class="text-[10px] text-gray-400">({{ $artifact->versions_count }} ver.)</span>
                                @endif
                            </div>
                        </div>
                    </button>
                @endforeach

                {{-- Failed Build Tasks --}}
                @foreach($failedTasks as $task)
                    <div class="flex w-full items-start gap-2 rounded-md border border-red-200 bg-red-50 px-2.5 py-2">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-red-700">{{ $task->name }}</p>
                            <div class="mt-0.5 flex items-center gap-1.5">
                                <span class="inline-flex rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-medium text-red-700">failed</span>
                                <span class="text-[10px] text-red-500">{{ $task->type }}</span>
                            </div>
                            @if($task->error)
                                <p class="mt-1 truncate text-[10px] text-red-500" title="{{ $task->error }}">{{ Str::limit($task->error, 60) }}</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Right Panel: Preview --}}
            <div class="flex flex-1 flex-col rounded-lg border border-gray-200 bg-white">
                @if($selectedArtifactId && $this->selectedArtifact)
                    @php
                        $artifact = $this->selectedArtifact;
                        $category = $this->contentCategory;
                        $hlLang = $this->highlightLanguage;
                    @endphp

                    {{-- Toolbar --}}
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-3 py-2">
                        {{-- Left: Preview/Source tabs --}}
                        <div class="flex rounded-md bg-gray-100 p-0.5">
                            <button @click="viewMode = 'preview'"
                                :class="viewMode === 'preview' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700'"
                                class="rounded px-2.5 py-1 text-xs font-medium transition">
                                Preview
                            </button>
                            <button @click="viewMode = 'source'"
                                :class="viewMode === 'source' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700'"
                                class="rounded px-2.5 py-1 text-xs font-medium transition">
                                Source
                            </button>
                        </div>

                        {{-- Center: Version dropdown --}}
                        @if($artifact->versions_count > 1)
                            <select wire:change="selectVersion($event.target.value)"
                                class="rounded-md border border-gray-200 bg-white px-2 py-1 text-xs text-gray-700 focus:border-primary-500 focus:ring-1 focus:ring-primary-500">
                                @foreach($artifact->versions as $ver)
                                    <option value="{{ $ver->version }}" {{ $selectedVersion === $ver->version ? 'selected' : '' }}>
                                        Version {{ $ver->version }} — {{ $ver->created_at->diffForHumans() }}
                                    </option>
                                @endforeach
                            </select>
                        @else
                            <span class="text-xs text-gray-400">v{{ $artifact->current_version }}</span>
                        @endif

                        {{-- Right: Actions --}}
                        <div class="flex items-center gap-1">
                            @if($category === 'html')
                                <button wire:click="toggleFullscreen"
                                    class="rounded p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
                                    title="Full-screen preview">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/>
                                    </svg>
                                </button>
                            @endif
                            <button wire:click="downloadArtifact"
                                class="rounded p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
                                title="Download">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                </svg>
                            </button>
                            <button @click="copyToClipboard()"
                                class="rounded p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
                                title="Copy to clipboard">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" x-show="!copied">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
                                </svg>
                                <svg class="h-4 w-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" x-show="copied" x-cloak>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    {{-- Content Area --}}
                    <div class="relative flex-1 overflow-hidden">
                        @if($previewContent !== null)
                            {{-- Preview Mode --}}
                            <div x-show="viewMode === 'preview'" x-cloak class="h-full">
                                @if($category === 'html')
                                    {{-- HTML: sandboxed iframe via route --}}
                                    <div wire:ignore class="h-full">
                                        <iframe
                                            src="{{ route('artifacts.render', ['artifact' => $artifact->id, 'version' => $selectedVersion]) }}"
                                            sandbox="allow-scripts"
                                            referrerpolicy="no-referrer"
                                            loading="lazy"
                                            title="Artifact Preview: {{ $artifact->name }}"
                                            class="h-full w-full border-0"
                                            style="min-height: 460px;"
                                        ></iframe>
                                    </div>
                                @elseif($category === 'markdown')
                                    {{-- Markdown: rendered via route in iframe for isolation --}}
                                    <div wire:ignore class="h-full">
                                        <iframe
                                            src="{{ route('artifacts.render', ['artifact' => $artifact->id, 'version' => $selectedVersion]) }}"
                                            sandbox=""
                                            referrerpolicy="no-referrer"
                                            loading="lazy"
                                            title="Artifact Preview: {{ $artifact->name }}"
                                            class="h-full w-full border-0"
                                            style="min-height: 460px;"
                                        ></iframe>
                                    </div>
                                @elseif($category === 'json')
                                    {{-- JSON: syntax highlighted --}}
                                    <div class="h-full overflow-auto bg-gray-50 p-4">
                                        <pre class="text-sm leading-relaxed"><code class="language-json hljs-code" x-ref="previewCode">{{ $previewContent }}</code></pre>
                                    </div>
                                @else
                                    {{-- Text: plain --}}
                                    <div class="h-full overflow-auto p-4">
                                        <pre class="text-sm leading-relaxed text-gray-700">{{ $previewContent }}</pre>
                                    </div>
                                @endif
                            </div>

                            {{-- Source Mode --}}
                            <div x-show="viewMode === 'source'" x-cloak class="h-full overflow-auto bg-gray-50 p-4">
                                <pre class="text-sm leading-relaxed"><code class="language-{{ $hlLang }} hljs-code" x-ref="sourceCode">{{ $previewContent }}</code></pre>
                            </div>
                        @else
                            {{-- Loading/No Content --}}
                            <div class="flex h-full items-center justify-center p-8">
                                <p class="text-sm text-gray-400">No content available for this version.</p>
                            </div>
                        @endif
                    </div>
                @else
                    {{-- No artifact selected --}}
                    <div class="flex flex-1 items-center justify-center p-8">
                        <div class="text-center">
                            <svg class="mx-auto h-8 w-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122"/>
                            </svg>
                            <p class="mt-2 text-sm text-gray-400">Select an artifact to preview</p>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- Full-Screen Modal --}}
        @if($showFullscreen && $selectedArtifactId && $this->selectedArtifact)
            @php $fsArtifact = $this->selectedArtifact; @endphp
            <div class="fixed inset-0 z-50 flex flex-col bg-white"
                 x-data="{ previewWidth: '100%' }"
                 @keydown.escape.window="$wire.toggleFullscreen()">

                {{-- Modal Toolbar --}}
                <div class="flex items-center justify-between border-b border-gray-200 bg-gray-50 px-4 py-2.5">
                    <div class="flex items-center gap-3">
                        <span class="text-sm font-semibold text-gray-900">{{ $fsArtifact->name }}</span>
                        <span class="rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-medium text-blue-700">
                            v{{ $selectedVersion ?? $fsArtifact->current_version }}
                        </span>
                    </div>

                    {{-- Device Width Presets --}}
                    <div class="flex items-center gap-1 rounded-md bg-gray-100 p-0.5">
                        <button @click="previewWidth = '375px'"
                            :class="previewWidth === '375px' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700'"
                            class="rounded px-2.5 py-1 text-xs font-medium transition" title="Mobile (375px)">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                            </svg>
                        </button>
                        <button @click="previewWidth = '768px'"
                            :class="previewWidth === '768px' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700'"
                            class="rounded px-2.5 py-1 text-xs font-medium transition" title="Tablet (768px)">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                            </svg>
                        </button>
                        <button @click="previewWidth = '1280px'"
                            :class="previewWidth === '1280px' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700'"
                            class="rounded px-2.5 py-1 text-xs font-medium transition" title="Desktop (1280px)">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                        </button>
                        <button @click="previewWidth = '100%'"
                            :class="previewWidth === '100%' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700'"
                            class="rounded px-2.5 py-1 text-xs font-medium transition" title="Full width">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/>
                            </svg>
                        </button>
                    </div>

                    {{-- Close + Download --}}
                    <div class="flex items-center gap-2">
                        <button wire:click="downloadArtifact"
                            class="rounded px-2.5 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-gray-100">
                            Download
                        </button>
                        <button wire:click="toggleFullscreen"
                            class="rounded bg-gray-200 px-2.5 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-300">
                            Close
                        </button>
                    </div>
                </div>

                {{-- Full-Screen Preview --}}
                <div class="flex flex-1 items-start justify-center overflow-auto bg-gray-100 p-4">
                    <div :style="{ width: previewWidth, maxWidth: '100%' }"
                         class="h-full bg-white shadow-lg transition-all duration-300"
                         :class="previewWidth !== '100%' && 'rounded-lg border border-gray-200'">
                        <iframe wire:ignore
                            src="{{ route('artifacts.render', ['artifact' => $fsArtifact->id, 'version' => $selectedVersion]) }}"
                            sandbox="allow-scripts"
                            referrerpolicy="no-referrer"
                            title="Full-screen preview: {{ $fsArtifact->name }}"
                            class="h-full w-full border-0"
                            style="min-height: calc(100vh - 70px);"
                        ></iframe>
                    </div>
                </div>
            </div>
        @endif
    @endif

    {{-- Highlight.js (loaded once, lightweight) --}}
    @once
        @push('styles')
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/styles/github.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
        @endpush
        @push('scripts')
            <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/highlight.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
        @endpush
    @endonce

    @script
    <script>
        Alpine.data('artifactPreview', (initialId) => ({
            viewMode: 'preview',
            copied: false,

            init() {
                this.$watch('viewMode', () => {
                    this.$nextTick(() => this.highlightCode());
                });

                // Highlight on initial load
                this.$nextTick(() => this.highlightCode());

                // Re-highlight when Livewire updates content
                Livewire.hook('morph.updated', ({ el }) => {
                    this.$nextTick(() => this.highlightCode());
                });
            },

            highlightCode() {
                if (typeof hljs === 'undefined') return;
                this.$el.querySelectorAll('code.hljs-code:not(.hljs)').forEach(el => {
                    hljs.highlightElement(el);
                });
            },

            copyToClipboard() {
                const content = @this.previewContent;
                if (!content) return;
                navigator.clipboard.writeText(content).then(() => {
                    this.copied = true;
                    setTimeout(() => this.copied = false, 2000);
                });
            },
        }));
    </script>
    @endscript
</div>
