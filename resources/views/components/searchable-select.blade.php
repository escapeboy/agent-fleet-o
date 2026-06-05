@props([
    'name',                 // Livewire property to bind (e.g. 'defaultModel')
    'options' => [],        // [value => ['label' => ..] | string]
    'label' => null,
    'placeholder' => 'Search…',
    'emptyOption' => null,  // label for a "clear/none" choice; null = omit
])

@php
    $opts = [];
    if ($emptyOption !== null) {
        $opts[] = ['value' => '', 'label' => $emptyOption];
    }
    foreach ($options as $val => $info) {
        $opts[] = [
            'value' => (string) $val,
            'label' => is_array($info) ? ($info['label'] ?? $val) : (string) $info,
        ];
    }
@endphp

<div
    x-data="{
        open: false,
        query: '',
        value: $wire.entangle('{{ $name }}'),
        options: {{ Illuminate\Support\Js::from($opts) }},
        get selectedLabel() {
            const o = this.options.find(o => String(o.value) === String(this.value));
            return o ? o.label : '';
        },
        get filtered() {
            const q = this.query.trim().toLowerCase();
            const list = q === ''
                ? this.options
                : this.options.filter(o => o.label.toLowerCase().includes(q) || String(o.value).toLowerCase().includes(q));
            return list.slice(0, 200);
        },
        pick(o) { this.value = o.value; this.query = ''; this.open = false; },
        toggle() { this.open = ! this.open; if (this.open) { this.$nextTick(() => this.$refs.search?.focus()); } },
    }"
    @click.outside="open = false"
    @keydown.escape="open = false"
    class="relative"
>
    @if($label)
        <label class="mb-1 block text-sm font-medium text-(--color-on-surface)">{{ $label }}</label>
    @endif

    <button type="button" @click="toggle()"
        class="flex w-full items-center justify-between rounded-lg border border-(--color-input-border) bg-(--color-input-bg) px-3 py-2.5 text-left text-sm text-(--color-on-surface) focus:border-primary-500 focus:ring-2 focus:ring-primary-500 focus:outline-none">
        <span class="truncate" x-text="selectedLabel || '{{ $placeholder }}'" :class="selectedLabel ? '' : 'text-gray-400'"></span>
        <svg class="ml-2 h-4 w-4 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
        </svg>
    </button>

    <div x-show="open" x-transition.opacity style="display:none"
        class="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border border-(--color-input-border) bg-(--color-input-bg) shadow-lg">
        <div class="border-b border-(--color-input-border) p-2">
            <input type="text" x-model="query" x-ref="search" @click.stop placeholder="Search…"
                class="w-full rounded-md border border-(--color-input-border) bg-(--color-surface) px-2 py-1.5 text-sm text-(--color-on-surface) focus:border-primary-500 focus:ring-1 focus:ring-primary-500 focus:outline-none">
        </div>
        <ul class="max-h-60 overflow-auto py-1">
            <template x-for="o in filtered" :key="o.value">
                <li>
                    <button type="button" @click="pick(o)"
                        class="block w-full truncate px-3 py-1.5 text-left text-sm text-(--color-on-surface) hover:bg-(--color-surface-alt)"
                        :class="String(o.value) === String(value) ? 'bg-(--color-surface-alt) font-medium' : ''"
                        x-text="o.label"></button>
                </li>
            </template>
            <li x-show="filtered.length === 0" class="px-3 py-2 text-sm text-gray-400">No matches</li>
        </ul>
    </div>
</div>
