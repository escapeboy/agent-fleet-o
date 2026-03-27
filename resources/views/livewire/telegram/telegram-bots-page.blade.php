<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Telegram Bots</h1>
            <p class="mt-1 text-sm text-gray-500">Connect Telegram bots to route messages to the assistant, projects, or trigger rules.</p>
        </div>
        @if($bots->isEmpty() || !$showForm)
            <button wire:click="openForm"
                class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Register Bot
            </button>
        @endif
    </div>

    @if($success)
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-700">{{ $success }}</div>
    @endif
    @if($error)
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">{{ $error }}</div>
    @endif

    {{-- Register form --}}
    @if($showForm)
        <div class="rounded-xl border border-primary-200 bg-white p-6 shadow-sm">
            <h2 class="mb-4 text-sm font-semibold text-gray-900">Register Telegram Bot</h2>

            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Bot Token <span class="text-red-500">*</span></label>
                    <input wire:model="botToken" type="password" placeholder="1234567890:ABCdefGHIjklMNOpqrSTUvwxYZ"
                        class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500" />
                    <p class="mt-1 text-xs text-gray-400">Get this from <a href="https://t.me/BotFather" target="_blank" class="text-primary-600 hover:underline">@BotFather</a> on Telegram.</p>
                    @error('botToken') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Routing Mode <span class="text-red-500">*</span></label>
                    <select wire:model.live="routingMode"
                        class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="assistant">Assistant — route to AI assistant chat</option>
                        <option value="project">Project — route to a specific project</option>
                        <option value="trigger_rules">Trigger Rules — evaluate against trigger rules</option>
                    </select>
                    @error('routingMode') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                @if($routingMode === 'project')
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Default Project <span class="text-red-500">*</span></label>
                        <select wire:model="defaultProjectId"
                            class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500">
                            <option value="">Select a project…</option>
                            @foreach($projects as $project)
                                <option value="{{ $project->id }}">{{ $project->name }}</option>
                            @endforeach
                        </select>
                        @error('defaultProjectId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>

            <div class="mt-6 flex items-center gap-3">
                <button wire:click="save"
                    wire:loading.attr="disabled"
                    class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="save">Register Bot</span>
                    <span wire:loading wire:target="save">Connecting…</span>
                </button>
                <button wire:click="cancelForm"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    {{-- Bot list --}}
    @forelse($bots as $bot)
        <div class="rounded-xl border bg-white shadow-sm {{ $bot->isActive() ? 'border-gray-200' : 'border-gray-100 opacity-70' }} p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex items-center gap-3">
                    {{-- Status indicator --}}
                    <div class="h-2.5 w-2.5 rounded-full {{ $bot->isActive() ? 'bg-green-500' : 'bg-gray-300' }}"></div>
                    <div>
                        <h3 class="font-semibold text-gray-900">
                            @if($bot->bot_username) @{{ $bot->bot_username }} @else {{ $bot->bot_name ?? 'Telegram Bot' }} @endif
                        </h3>
                        @if($bot->bot_name && $bot->bot_username)
                            <p class="text-xs text-gray-500">{{ $bot->bot_name }}</p>
                        @endif
                    </div>
                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">
                        {{ match($bot->routing_mode) {
                            'assistant' => 'Assistant',
                            'project' => 'Project',
                            'trigger_rules' => 'Trigger Rules',
                            default => $bot->routing_mode,
                        } }}
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <button wire:click="toggleStatus('{{ $bot->id }}')"
                        class="rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors
                            {{ $bot->isActive()
                                ? 'border-gray-300 text-gray-700 hover:bg-gray-50'
                                : 'border-green-300 text-green-700 hover:bg-green-50' }}">
                        {{ $bot->isActive() ? 'Disable' : 'Enable' }}
                    </button>
                    <button wire:click="delete('{{ $bot->id }}')"
                        wire:confirm="Remove this Telegram bot? This will stop all message processing."
                        class="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50">
                        Remove
                    </button>
                </div>
            </div>

            {{-- Stats row --}}
            <div class="mt-4 flex flex-wrap items-center gap-6 text-xs text-gray-500">
                <span>
                    <strong class="text-gray-700">{{ $bot->chatBindings->count() }}</strong> active chats
                </span>
                @if($bot->routing_mode === 'project' && $bot->defaultProject)
                    <span>Project: <strong class="text-gray-700">{{ $bot->defaultProject->name }}</strong></span>
                @endif
                @if($bot->last_message_at)
                    <span>Last message: <strong class="text-gray-700">{{ $bot->last_message_at->diffForHumans() }}</strong></span>
                @endif
            </div>

            {{-- Error alert --}}
            @if($bot->last_error)
                <div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                    <strong>Error:</strong> {{ $bot->last_error }}
                </div>
            @endif

            {{-- Webhook info --}}
            <div class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500">
                Webhook URL: <code class="font-mono text-gray-700">{{ url('/api/telegram/webhook/' . auth()->user()->current_team_id) }}</code>
            </div>
        </div>
    @empty
        @if(!$showForm)
            <div class="rounded-xl border border-dashed border-gray-200 bg-white p-12 text-center">
                <svg class="mx-auto h-10 w-10 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
                </svg>
                <p class="mt-3 text-sm text-gray-500">No Telegram bots configured yet.</p>
                <p class="mt-1 text-xs text-gray-400">Register a bot to route Telegram messages to the assistant, projects, or triggers.</p>
                <button wire:click="openForm"
                    class="mt-4 inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Register Your First Bot
                </button>
            </div>
        @endif
    @endforelse
</div>
