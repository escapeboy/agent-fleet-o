<div class="rounded-xl border border-gray-200 bg-white p-6">
    {{-- Header --}}
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h3 class="text-lg font-semibold text-gray-900">
                {{ $task->context['node_label'] ?? 'Human Task' }}
            </h3>
            @if($task->context['instructions'] ?? null)
                <p class="mt-1 text-sm text-gray-600">{{ $task->context['instructions'] }}</p>
            @endif
        </div>

        @if($task->sla_deadline)
            <div class="text-right">
                <span class="text-xs text-gray-500">SLA Deadline</span>
                <p class="text-sm font-medium {{ $task->isSlaExpired() ? 'text-red-600' : 'text-gray-700' }}">
                    {{ $task->sla_deadline->diffForHumans() }}
                </p>
            </div>
        @endif
    </div>

    @if(session()->has('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">
            {{ session('message') }}
        </div>
    @endif

    {{-- Dynamic Form --}}
    @if($task->status === \App\Domain\Approval\Enums\ApprovalStatus::Pending)
        <form wire:submit="submit" class="space-y-4">
            @php $fields = $task->form_schema['fields'] ?? []; @endphp

            @foreach($fields as $field)
                @php
                    $name = $field['name'] ?? '';
                    $type = $field['type'] ?? 'text';
                    $label = $field['label'] ?? ucfirst(str_replace('_', ' ', $name));
                    $required = $field['required'] ?? false;
                    $hint = $field['hint'] ?? null;
                @endphp

                @if($type === 'textarea')
                    <x-form-textarea
                        wire:model="formData.{{ $name }}"
                        :label="$label"
                        :hint="$hint"
                        rows="{{ $field['rows'] ?? 3 }}"
                        :required="$required"
                    />
                @elseif($type === 'select')
                    <x-form-select
                        wire:model="formData.{{ $name }}"
                        :label="$label"
                        :required="$required"
                    >
                        <option value="">-- Select --</option>
                        @foreach($field['options'] ?? [] as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </x-form-select>
                @elseif($type === 'checkbox')
                    <x-form-checkbox
                        wire:model="formData.{{ $name }}"
                        :label="$label"
                    />
                @else
                    <x-form-input
                        wire:model="formData.{{ $name }}"
                        :type="$type"
                        :label="$label"
                        :hint="$hint"
                        :required="$required"
                    />
                @endif

                @error("formData.{$name}")
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            @endforeach

            <x-form-textarea
                wire:model="reviewerNotes"
                label="Notes (optional)"
                rows="2"
                hint="Additional notes for this task"
            />

            {{-- Actions --}}
            <div class="flex items-center gap-3 border-t border-gray-100 pt-4">
                <button type="submit"
                    class="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">
                    Complete Task
                </button>
                <button type="button" wire:click="$set('showRejectModal', true)"
                    class="rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50">
                    Reject
                </button>
            </div>
        </form>

        {{-- Reject Modal --}}
        @if($showRejectModal)
            <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4">
                <p class="text-sm font-medium text-red-800">Reject this task?</p>
                <x-form-textarea wire:model="rejectionReason" rows="2"
                    placeholder="Reason for rejection"
                    class="mt-2 border-red-300 focus:border-red-500 focus:ring-red-500" />
                @error('rejectionReason')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
                <div class="mt-3 flex gap-2">
                    <button wire:click="reject"
                        class="rounded-lg bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700">
                        Confirm Reject
                    </button>
                    <button wire:click="$set('showRejectModal', false)"
                        class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">
                        Cancel
                    </button>
                </div>
            </div>
        @endif
    @else
        {{-- Read-only view for completed/rejected tasks --}}
        <div class="rounded-lg bg-gray-50 p-4">
            <div class="mb-2 text-xs font-medium text-gray-500">
                Status: <x-status-badge :status="$task->status->value" />
            </div>

            @if($task->form_response)
                <dl class="mt-3 space-y-2">
                    @foreach($task->form_response as $key => $value)
                        <div>
                            <dt class="text-xs font-medium text-gray-500">{{ ucfirst(str_replace('_', ' ', $key)) }}</dt>
                            <dd class="mt-0.5 text-sm text-gray-900">{{ is_bool($value) ? ($value ? 'Yes' : 'No') : $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif

            @if($task->reviewer_notes)
                <p class="mt-3 text-xs text-gray-500">Notes: {{ $task->reviewer_notes }}</p>
            @endif
        </div>
    @endif
</div>
