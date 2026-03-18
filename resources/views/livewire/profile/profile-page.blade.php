<div
    x-data="{
        tab: window.location.hash || '#profile',
        setTab(t) {
            this.tab = t;
            window.location.hash = t;
        }
    }"
    @hashchange.window="tab = window.location.hash || '#profile'"
>
    {{-- Tab navigation --}}
    <div class="mb-6 border-b border-gray-200">
        <nav class="-mb-px flex gap-6 overflow-x-auto" aria-label="Profile sections">
            @foreach([
                '#profile'            => 'Profile',
                '#security'           => 'Security',
                '#connected-accounts' => 'Connected Accounts',
                '#notifications'      => 'Notifications',
            ] as $hash => $label)
                <button
                    @click="setTab('{{ $hash }}')"
                    :class="tab === '{{ $hash }}'
                        ? 'border-primary-500 text-primary-600'
                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="shrink-0 border-b-2 pb-3 text-sm font-medium transition-colors focus:outline-none">
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Profile tab --}}
    <div x-show="tab === '#profile'" x-cloak>
        <livewire:profile.update-profile-information-form />
    </div>

    {{-- Security tab --}}
    <div x-show="tab === '#security'" x-cloak>
        <div class="space-y-8">
            <livewire:profile.update-password-form />
            <livewire:profile.two-factor-authentication-form />
            <div class="rounded-lg border border-gray-200 bg-white p-6">
                <livewire:profile.passkeys-form />
            </div>
        </div>
    </div>

    {{-- Connected Accounts tab --}}
    <div x-show="tab === '#connected-accounts'" x-cloak>
        <livewire:profile.connected-accounts-form />
    </div>

    {{-- Notifications tab --}}
    <div x-show="tab === '#notifications'" x-cloak>
        <livewire:profile.notification-preferences-form />
    </div>
</div>
