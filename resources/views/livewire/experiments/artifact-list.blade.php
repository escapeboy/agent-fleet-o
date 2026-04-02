<div>
    @if($artifacts->isEmpty() && $failedTasks->isEmpty())
        {{-- Empty State --}}
        <div class="rounded-lg border border-gray-200 bg-white p-8 text-center">
            <i class="fa-regular fa-file text-3xl text-gray-300"></i>
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
                            @if($artifacts->count() > 1)
                                <button wire:click="downloadAllAsZip"
                                    class="flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-gray-500 transition hover:bg-gray-100 hover:text-gray-700"
                                    title="Download all as ZIP">
                                    <i class="fa-solid fa-download text-sm"></i>
                                    ZIP
                                </button>
                            @endif
                            @if($category === 'html')
                                <button wire:click="toggleFullscreen"
                                    class="rounded p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
                                    title="Full-screen preview">
                                    <i class="fa-solid fa-expand text-base"></i>
                                </button>
                            @endif
                            <button wire:click="downloadArtifact"
                                class="rounded p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
                                title="Download">
                                <i class="fa-solid fa-download text-base"></i>
                            </button>
                            <button @click="copyToClipboard()"
                                class="rounded p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
                                title="Copy to clipboard">
                                <i class="fa-regular fa-clipboard text-base" x-show="!copied"></i>
                                <i class="fa-solid fa-check text-base text-green-500" x-show="copied" x-cloak></i>
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
                                    <div wire:key="preview-{{ $artifact->id }}-{{ $selectedVersion }}" class="h-full">
                                        <iframe
                                            src="{{ route('artifacts.render', ['artifact' => $artifact->id, 'version' => $selectedVersion]) }}"
                                            sandbox="allow-same-origin allow-scripts"
                                            referrerpolicy="no-referrer"
                                            loading="lazy"
                                            title="Artifact Preview: {{ $artifact->name }}"
                                            class="h-full w-full border-0"
                                            style="min-height: 460px;"
                                        ></iframe>
                                    </div>
                                @elseif($category === 'markdown')
                                    {{-- Markdown: rendered via route in iframe for isolation --}}
                                    <div wire:key="preview-md-{{ $artifact->id }}-{{ $selectedVersion }}" class="h-full">
                                        <iframe
                                            src="{{ route('artifacts.render', ['artifact' => $artifact->id, 'version' => $selectedVersion]) }}"
                                            sandbox="allow-same-origin"
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
                            <i class="fa-solid fa-eye text-2xl text-gray-300"></i>
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
                            <i class="fa-solid fa-mobile-screen text-sm"></i>
                        </button>
                        <button @click="previewWidth = '768px'"
                            :class="previewWidth === '768px' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700'"
                            class="rounded px-2.5 py-1 text-xs font-medium transition" title="Tablet (768px)">
                            <i class="fa-solid fa-tablet-screen-button text-sm"></i>
                        </button>
                        <button @click="previewWidth = '1280px'"
                            :class="previewWidth === '1280px' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700'"
                            class="rounded px-2.5 py-1 text-xs font-medium transition" title="Desktop (1280px)">
                            <i class="fa-solid fa-desktop text-sm"></i>
                        </button>
                        <button @click="previewWidth = '100%'"
                            :class="previewWidth === '100%' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700'"
                            class="rounded px-2.5 py-1 text-xs font-medium transition" title="Full width">
                            <i class="fa-solid fa-expand text-sm"></i>
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
                        <iframe
                            wire:key="fs-{{ $fsArtifact->id }}-{{ $selectedVersion }}"
                            src="{{ route('artifacts.render', ['artifact' => $fsArtifact->id, 'version' => $selectedVersion]) }}"
                            sandbox="allow-same-origin allow-scripts"
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
