<div x-data="{
        open: false,
        theme: @entangle('theme'),
        isDark(t) { return t.includes('-dark') || ['catppuccin', 'monokai', 'dracula', 'nord', 'solarized'].includes(t) },
        applyTheme(t) {
            document.documentElement.setAttribute('data-theme', t);
            localStorage.setItem('fleetq-theme', t);
        },
        toggleMode() {
            const themes = {{ Js::from($themes) }};
            const base = this.getBase(this.theme);
            const config = themes[base];
            if (!config) return;

            if (this.isDark(this.theme)) {
                this.setTheme(config.light ?? base);
            } else {
                this.setTheme(config.dark ?? base + '-dark');
            }
        },
        getBase(t) {
            return t.replace('-dark', '').replace('-light', '');
        },
        setTheme(t) {
            this.theme = t;
            this.applyTheme(t);
            $wire.setTheme(t);
        }
    }"
    x-init="applyTheme(theme)"
    @theme-changed.window="applyTheme($event.detail.theme)"
    class="relative"
>
    {{-- Theme toggle button --}}
    <button
        @click="open = !open"
        @click.away="open = false"
        class="flex items-center gap-1.5 rounded-lg px-2 py-1.5 text-sm text-gray-500 transition hover:bg-gray-100 hover:text-gray-700"
        title="Change theme"
    >
        {{-- Sun/Moon icon --}}
        <template x-if="isDark(theme)">
            <i class="fa-solid fa-moon text-base"></i>
        </template>
        <template x-if="!isDark(theme)">
            <i class="fa-solid fa-sun text-base"></i>
        </template>
        <i class="fa-solid fa-chevron-down text-xs"></i>
    </button>

    {{-- Dropdown --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="transform opacity-0 scale-95"
        x-transition:enter-end="transform opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="transform opacity-100 scale-100"
        x-transition:leave-end="transform opacity-0 scale-95"
        class="absolute right-0 z-50 mt-2 w-56 origin-top-right rounded-lg border border-gray-200 bg-white py-1 shadow-lg"
    >
        <div class="px-3 py-2 text-xs font-semibold uppercase tracking-wider text-gray-400">Theme</div>

        @foreach($themes as $key => $config)
            <button
                @click="setTheme('{{ $key }}'); open = false"
                class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm transition hover:bg-gray-50"
                :class="{ 'bg-blue-50 text-blue-700 font-medium': getBase(theme) === '{{ $key }}' }"
            >
                <span>{{ $config['icon'] }}</span>
                <span>{{ $config['label'] }}</span>
                <template x-if="getBase(theme) === '{{ $key }}'">
                    <i class="fa-solid fa-check ml-auto text-base text-blue-500"></i>
                </template>
            </button>
        @endforeach

        <div class="my-1 border-t border-gray-100"></div>

        {{-- Light/Dark toggle --}}
        <button
            @click="toggleMode(); open = false"
            class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm transition hover:bg-gray-50"
        >
            <template x-if="isDark(theme)">
                <span>☀️</span>
            </template>
            <template x-if="!isDark(theme)">
                <span>🌙</span>
            </template>
            <span x-text="isDark(theme) ? 'Switch to Light' : 'Switch to Dark'"></span>
        </button>
    </div>
</div>
