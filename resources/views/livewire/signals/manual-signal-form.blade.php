<div class="mx-auto max-w-2xl space-y-6">

    @if(session('signal_created'))
        <div class="rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('signal_created') }}</div>
    @endif

    {{-- Step 1: Free-text intake --}}
    <div class="rounded-xl border border-gray-200 bg-white p-6">
        <h2 class="mb-1 text-sm font-semibold text-gray-900">Describe your request</h2>
        <p class="mb-4 text-xs text-gray-500">Write freely — AI will structure it for you.</p>

        <x-form-textarea
            wire:model="rawText"
            label=""
            placeholder="e.g. The checkout button on mobile Safari is broken for users with iOS 17. Steps to reproduce: add item to cart, tap checkout, nothing happens. Urgent — affects all mobile users."
            rows="5"
        />

        @error('rawText')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror

        <button
            wire:click="structureWithAi"
            wire:loading.attr="disabled"
            class="mt-3 inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-60 transition">
            <span wire:loading.remove wire:target="structureWithAi">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.345.345a3.75 3.75 0 01-5.197-5.197 3 3 0 00-.879-5.123 3 3 0 00-3.7 3.1 3.75 3.75 0 01-5.198 5.198l-.344-.345a5 5 0 017.07 0z" /></svg>
                Structure with AI
            </span>
            <span wire:loading wire:target="structureWithAi">Structuring…</span>
        </button>
    </div>

    {{-- Step 2: Structured fields (shown after AI or directly editable) --}}
    <div @class(['rounded-xl border border-gray-200 bg-white p-6 space-y-4', 'opacity-50 pointer-events-none' => $isStructuring])>
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900">Structured fields</h2>
            @if($isStructured)
                <span class="flex items-center gap-1 text-xs text-green-600">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                    AI structured
                </span>
            @endif
        </div>

        <x-form-input wire:model="title" label="Title" placeholder="Brief summary" />

        <x-form-textarea wire:model="description" label="Description" rows="3" placeholder="Detailed description" />

        <div class="grid grid-cols-2 gap-4">
            <x-form-select wire:model="priority" label="Priority">
                <option value="low">Low</option>
                <option value="medium">Medium</option>
                <option value="high">High</option>
                <option value="critical">Critical</option>
            </x-form-select>

            <x-form-select wire:model="sourceType" label="Type">
                <option value="manual">Manual</option>
                <option value="bug_report">Bug Report</option>
                <option value="feature_request">Feature Request</option>
                <option value="support_ticket">Support Ticket</option>
                <option value="feedback">Feedback</option>
                <option value="alert">Alert</option>
                <option value="incident">Incident</option>
                <option value="task">Task</option>
            </x-form-select>
        </div>

        <x-form-input wire:model="tagsInput" label="Tags" placeholder="bug, mobile, checkout" hint="Comma-separated" />

        @if(!empty($metadata))
            <div>
                <p class="mb-1.5 text-xs font-medium text-gray-700">Extracted Metadata</p>
                <div class="rounded-lg border border-gray-100 bg-gray-50 p-3 space-y-1">
                    @foreach($metadata as $key => $value)
                        <div class="flex gap-2 text-xs">
                            <span class="font-medium text-gray-600 w-32 shrink-0">{{ str_replace('_', ' ', $key) }}</span>
                            <span class="text-gray-700">{{ is_array($value) ? implode(', ', $value) : $value }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="flex justify-end pt-2">
            <button
                wire:click="submit"
                wire:loading.attr="disabled"
                class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-5 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:opacity-60 transition">
                <span wire:loading.remove wire:target="submit">Create Signal</span>
                <span wire:loading wire:target="submit">Creating…</span>
            </button>
        </div>
    </div>

</div>
