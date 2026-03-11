<div class="mx-auto max-w-2xl">
    <form wire:submit="save" class="space-y-6">

        {{-- Basic Info --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6 space-y-4">
            <h2 class="text-base font-semibold text-gray-900">Basic Information</h2>

            <x-form-input wire:model="name" label="Name" placeholder="Customer Support Bot" :error="$errors->first('name')" />

            <x-form-select wire:model="type" label="Type">
                @foreach(\App\Domain\Chatbot\Enums\ChatbotType::cases() as $type)
                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                @endforeach
            </x-form-select>

            <x-form-textarea wire:model="description" label="Description" placeholder="What does this chatbot do?" rows="2" />

            <x-form-input wire:model="welcomeMessage" label="Welcome Message" placeholder="Hi! How can I help you today?" />

            <x-form-input wire:model="fallbackMessage" label="Fallback Message" placeholder="I'm not sure how to help with that..." />
        </div>

        {{-- Agent Configuration --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6 space-y-4">
            <h2 class="text-base font-semibold text-gray-900">AI Agent</h2>

            <div class="flex gap-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" wire:model.live="agentMode" value="new" class="text-primary-600 focus:ring-primary-500">
                    <span class="text-sm font-medium text-gray-700">Create new agent</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" wire:model.live="agentMode" value="existing" class="text-primary-600 focus:ring-primary-500">
                    <span class="text-sm font-medium text-gray-700">Use existing agent</span>
                </label>
            </div>

            @if($agentMode === 'new')
                <x-form-select wire:model.live="provider" label="Provider">
                    @foreach($providers as $key => $providerData)
                        <option value="{{ $key }}">{{ $providerData['name'] }}</option>
                    @endforeach
                </x-form-select>

                <x-form-select wire:model="model" label="Model">
                    @foreach($providers[$provider]['models'] ?? [] as $modelKey => $modelData)
                        <option value="{{ $modelKey }}">{{ $modelData['label'] ?? $modelKey }}</option>
                    @endforeach
                </x-form-select>

                <x-form-textarea wire:model="systemPrompt" label="System Prompt" placeholder="You are a helpful customer support assistant for Acme Corp..." rows="4"
                    hint="Instructions for the AI. Leave blank to use the default." />
            @else
                <x-form-select wire:model="existingAgentId" label="Select Agent" :error="$errors->first('existingAgentId')">
                    <option value="">— Choose an agent —</option>
                    @foreach($existingAgents as $agent)
                        <option value="{{ $agent->id }}">{{ $agent->name }} ({{ $agent->provider }}/{{ $agent->model }})</option>
                    @endforeach
                </x-form-select>
            @endif
        </div>

        {{-- Actions --}}
        <div class="flex justify-end gap-3">
            <a href="{{ route('chatbots.index') }}"
               class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Cancel
            </a>
            <button type="submit"
                    class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50"
                    wire:loading.attr="disabled">
                <span wire:loading.remove>Create Chatbot</span>
                <span wire:loading>Creating...</span>
            </button>
        </div>
    </form>
</div>
