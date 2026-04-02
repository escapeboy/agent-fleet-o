<div class="w-full max-w-lg px-4 py-8">
    {{-- Header --}}
    <div class="mb-8 text-center">
        <div class="mb-3 flex justify-center">
            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-primary-600">
                <x-logo-icon class="h-7 w-7 text-white" />
            </div>
        </div>
        <h1 class="text-2xl font-bold text-gray-900">{{ config('app.name') }}</h1>
        <p class="mt-2 text-sm text-gray-500">Installation Setup</p>
    </div>

    {{-- System Checks --}}
    <div class="mb-6 rounded-xl border border-gray-200 bg-white p-6">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-semibold text-gray-900">System Checks</h2>
            <button
                wire:click="recheck"
                wire:loading.attr="disabled"
                class="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100 disabled:opacity-50"
            >
                <i wire:loading wire:target="recheck" class="fa-solid fa-spinner fa-spin text-sm"></i>
                <i wire:loading.remove wire:target="recheck" class="fa-solid fa-rotate text-sm"></i>
                Re-check
            </button>
        </div>

        <div class="space-y-3">
            @foreach($checks as $key => $check)
                @php
                    $labels = [
                        'database'   => 'Database Connection',
                        'migrations' => 'Database Schema',
                        'app_key'    => 'Application Key',
                        'redis'      => 'Redis Connection',
                        'llm'        => 'LLM Provider',
                    ];
                    $label = $labels[$key] ?? ucfirst($key);
                @endphp
                <div class="flex items-start gap-3 rounded-lg p-3 {{ $check['status'] === 'ok' ? 'bg-green-50' : ($check['status'] === 'warn' ? 'bg-yellow-50' : 'bg-red-50') }}">
                    <div class="mt-0.5 flex-shrink-0">
                        @if($check['status'] === 'ok')
                            <i class="fa-solid fa-circle-check text-lg text-green-500"></i>
                        @elseif($check['status'] === 'warn')
                            <i class="fa-solid fa-triangle-exclamation text-lg text-yellow-500"></i>
                        @else
                            <i class="fa-solid fa-circle-xmark text-lg text-red-500"></i>
                        @endif
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium {{ $check['status'] === 'ok' ? 'text-green-800' : ($check['status'] === 'warn' ? 'text-yellow-800' : 'text-red-800') }}">
                                {{ $label }}
                            </span>
                            @if($check['status'] === 'warn')
                                <span class="rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-700">Optional</span>
                            @endif
                        </div>
                        <p class="mt-0.5 text-xs {{ $check['status'] === 'ok' ? 'text-green-700' : ($check['status'] === 'warn' ? 'text-yellow-700' : 'text-red-700') }}">
                            {{ $check['detail'] }}
                        </p>
                        @if(isset($check['hint']))
                            <p class="mt-1 text-xs text-gray-600">
                                <span class="font-medium">Fix:</span> {{ $check['hint'] }}
                            </p>
                        @endif
                    </div>
                </div>
            @endforeach

            @if(empty($checks))
                <div class="flex items-center gap-2 py-2 text-sm text-gray-500">
                    <i class="fa-solid fa-spinner fa-spin text-base"></i>
                    Running checks...
                </div>
            @endif
        </div>
    </div>

    {{-- Admin Account Form --}}
    @if(! $blockerPresent && ! empty($checks))
        {{-- No-password mode tip --}}
        <div class="mb-4 rounded-xl border border-blue-200 bg-blue-50 p-4">
            <div class="flex gap-3">
                <i class="fa-solid fa-circle-info mt-0.5 text-lg flex-shrink-0 text-blue-500"></i>
                <div>
                    <p class="text-sm font-medium text-blue-800">Running locally? Skip the password.</p>
                    <p class="mt-1 text-xs text-blue-700">
                        If this is a local install on your own machine, you can enable no-password mode — the app will log you in automatically on every visit.
                    </p>
                    <p class="mt-2 text-xs text-blue-700">
                        Add this to your <code class="rounded bg-blue-100 px-1 py-0.5 font-mono">.env</code> file:
                    </p>
                    <pre class="mt-1.5 rounded-md bg-blue-100 px-3 py-2 text-xs font-mono text-blue-900">APP_AUTH_BYPASS=true
APP_ENV=local</pre>
                    <p class="mt-2 text-xs text-blue-600">
                        With bypass enabled you still need an account below — but you'll never be asked for a password again.
                        <strong>Never use this on a server accessible from the internet.</strong>
                    </p>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <h2 class="mb-1 text-base font-semibold text-gray-900">Create Admin Account</h2>
            <p class="mb-5 text-sm text-gray-500">This will be the owner account for your installation.</p>

            @if($errors->any())
                <div class="mb-4 rounded-lg bg-red-50 p-4 text-sm text-red-600">
                    @foreach($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <form wire:submit="createAccount" method="POST" class="space-y-4">
                @csrf
                <div>
                    <x-form-input
                        label="Full Name"
                        type="text"
                        id="name"
                        name="name"
                        wire:model="name"
                        required
                        autofocus
                    />
                </div>

                <div>
                    <x-form-input
                        label="Email Address"
                        type="email"
                        id="email"
                        name="email"
                        wire:model="email"
                        required
                    />
                </div>

                <div>
                    <x-form-input
                        label="Password"
                        type="password"
                        id="password"
                        name="password"
                        wire:model="password"
                        required
                    />
                </div>

                <div>
                    <x-form-input
                        label="Confirm Password"
                        type="password"
                        id="password_confirmation"
                        name="password_confirmation"
                        wire:model="password_confirmation"
                        required
                    />
                </div>

                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    class="w-full rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="createAccount">Create Account & Launch</span>
                    <span wire:loading wire:target="createAccount">Setting up...</span>
                </button>
            </form>
        </div>
    @elseif($blockerPresent && ! empty($checks))
        <div class="rounded-xl border border-red-200 bg-red-50 p-5 text-center">
            <p class="text-sm font-medium text-red-800">Fix the issues above before continuing.</p>
            <p class="mt-1 text-xs text-red-600">After making changes to your .env file, click "Re-check" above.</p>
        </div>
    @endif
</div>
