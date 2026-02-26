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
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
            </svg>
        </template>
        <template x-if="!isDark(theme)">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
            </svg>
        </template>
        <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
        </svg>
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
                    <svg class="ml-auto h-4 w-4 text-blue-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                    </svg>
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
