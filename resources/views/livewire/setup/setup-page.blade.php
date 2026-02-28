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
                <svg wire:loading wire:target="recheck" class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <svg wire:loading.remove wire:target="recheck" class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
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
                            <svg class="h-5 w-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                        @elseif($check['status'] === 'warn')
                            <svg class="h-5 w-5 text-yellow-500" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                            </svg>
                        @else
                            <svg class="h-5 w-5 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                            </svg>
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
                    <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
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
                <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-blue-500" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                </svg>
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
