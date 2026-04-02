<div class="mx-auto max-w-3xl">
    <div class="rounded-xl border border-gray-200 bg-white p-6">
        {{-- Header --}}
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h2 class="text-xl font-bold text-gray-900">Quick Agent</h2>
                <p class="mt-1 text-sm text-gray-500">Describe your agent in plain text or markdown. Optionally schedule it to run automatically.</p>
            </div>
            <a href="{{ route('agents.create') }}" class="text-sm text-primary-600 hover:text-primary-800">
                Advanced Form &rarr;
            </a>
        </div>

        {{-- Example --}}
        <div class="mb-6 rounded-lg border border-gray-200 bg-gray-50 p-4" x-data="{ open: false }">
            <button @click="open = !open" class="flex w-full items-center justify-between text-sm font-medium text-gray-700">
                <span>Example: pipe.md format</span>
                <svg class="h-4 w-4 transition" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <pre x-show="open" x-cloak class="mt-3 overflow-x-auto rounded bg-white p-3 text-xs text-gray-600 border border-gray-200">---
role: Research Analyst
goal: Monitor competitor pricing and summarize changes
tone: professional
style: concise
---

You are a market research analyst. Every day, check the main competitor
websites for pricing changes. Summarize findings in a structured report
with: product, old price, new price, % change.

Focus on the top 10 products by revenue impact. Flag anything with
more than 10% price movement as urgent.</pre>
        </div>

        <div class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form-input wire:model="name" label="Agent Name" type="text" placeholder="e.g. Competitor Price Monitor"
                    :error="$errors->first('name')" />

                <x-form-select wire:model="schedule" label="Schedule">
                    <option value="manual">Manual (no schedule)</option>
                    <option value="hourly">Every hour</option>
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="monthly">Monthly</option>
                </x-form-select>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form-select wire:model="provider" label="Provider">
                    <option value="anthropic">Anthropic</option>
                    <option value="openai">OpenAI</option>
                    <option value="google">Google</option>
                </x-form-select>
                <x-form-input wire:model="model" label="Model" type="text" placeholder="claude-sonnet-4-5"
                    :error="$errors->first('model')" />
            </div>

            {{-- Markdown editor --}}
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">Agent Prompt</label>
                <textarea wire:model="markdown" rows="12" placeholder="Describe what the agent should do...

You can use optional frontmatter:
---
role: ...
goal: ...
tone: ...
---

Then write the full prompt / backstory below."
                    class="w-full rounded-lg border border-gray-300 px-3 py-2.5 font-mono text-sm focus:border-primary-500 focus:ring-primary-500"></textarea>
                @error('markdown') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-gray-400">The body becomes the agent's backstory/system prompt. Frontmatter fields (role, goal, tone, style) are extracted automatically.</p>
            </div>

            @if($schedule !== 'manual')
                <div class="flex items-center gap-2">
                    <input wire:model="createProject" type="checkbox" id="createProject" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                    <label for="createProject" class="text-sm text-gray-700">Create a scheduled project for this agent</label>
                </div>
            @endif
        </div>

        <div class="mt-6 flex items-center justify-end gap-3">
            <a href="{{ route('agents.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
            <button wire:click="save" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                Create Agent
            </button>
        </div>
    </div>
</div>
