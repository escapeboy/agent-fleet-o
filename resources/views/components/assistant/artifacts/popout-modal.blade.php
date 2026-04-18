{{--
  Pop-out modal for wide artifacts (data_table with 10+ rows, wide charts).
  Opens via window.dispatchEvent(new CustomEvent('artifact-popout', {detail: {...}})).

  Alpine-based: no server roundtrip, no Livewire state. The payload is
  serialized to a data- attribute on the trigger button and picked up here.

  Focus trap + Esc-to-close + click-outside-to-close. Reuses the existing
  tokens (indigo, rounded-xl, border-gray-200).
--}}
<div
    x-data="{
        open: false,
        payload: null,
        close() { this.open = false; this.payload = null; document.body.style.overflow = ''; },
    }"
    @artifact-popout.window="
        payload = $event.detail.payload || null;
        if (payload) {
            open = true;
            document.body.style.overflow = 'hidden';
            $nextTick(() => $refs.closeBtn?.focus());
        }
    "
    @keydown.escape.window="if (open) close()"
    x-show="open"
    x-transition.opacity
    x-cloak
    class="fixed inset-0 z-[100] flex items-center justify-center bg-gray-900/60 p-4"
    @click.self="close()"
    role="dialog"
    aria-modal="true"
    aria-label="Artifact detail view"
>
    <div
        x-show="open"
        x-transition.scale.origin.center.95
        class="relative max-h-[90vh] w-full max-w-4xl overflow-hidden rounded-xl bg-white shadow-2xl"
    >
        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-gray-900" x-text="payload?.title || payload?.type || 'Details'"></h2>
            <button
                x-ref="closeBtn"
                type="button"
                @click="close()"
                aria-label="Close modal"
                class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
            >
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="max-h-[calc(90vh-4rem)] overflow-auto p-4" x-show="payload">
            {{-- Table rendering for data_table payloads (the only type that currently --}}
            {{-- needs the pop-out — 50 rows max, all visible here). For other types the --}}
            {{-- pop-out is mostly a size increase without a row count change. --}}
            <template x-if="payload?.type === 'data_table'">
                <div>
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-gray-200 text-gray-500">
                            <tr>
                                <template x-for="col in payload.columns" :key="col.key">
                                    <th class="pb-1.5 pr-3 font-medium" x-text="col.label"></th>
                                </template>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <template x-for="(row, idx) in payload.rows" :key="idx">
                                <tr class="text-gray-700">
                                    <template x-for="col in payload.columns" :key="col.key">
                                        <td class="py-1.5 pr-3" x-text="row[col.key] || ''"></td>
                                    </template>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <p class="mt-3 text-[11px] text-gray-500">
                        Total <span x-text="payload.rows?.length || 0"></span> rows · source
                        <span class="font-mono" x-text="payload.source_tool || 'unknown'"></span>
                    </p>
                </div>
            </template>

            {{-- Fallback: pretty-print JSON for any non-table artifact. --}}
            <template x-if="payload?.type !== 'data_table'">
                <pre class="whitespace-pre-wrap font-mono text-[11px] text-gray-700" x-text="JSON.stringify(payload, null, 2)"></pre>
            </template>
        </div>
    </div>
</div>
