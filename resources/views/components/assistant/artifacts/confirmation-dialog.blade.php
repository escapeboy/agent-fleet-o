@props(['payload' => [], 'messageId' => null])

@php
    // NOT collapsible by design — destructive prompts must be visible.
    $title = $payload['title'] ?? 'Confirm action';
    $body = $payload['body'] ?? '';
    $confirmLabel = $payload['confirm_label'] ?? 'Confirm';
    $cancelLabel = $payload['cancel_label'] ?? 'Cancel';
    $destructive = $payload['destructive'] ?? false;
@endphp

<div
    class="mt-3 rounded-xl border p-3 {{ $destructive ? 'border-amber-300 bg-amber-50' : 'border-indigo-200 bg-indigo-50' }}"
    role="alertdialog"
    aria-labelledby="confirm-{{ $messageId }}-title"
    aria-describedby="confirm-{{ $messageId }}-body"
>
    <div class="flex items-start gap-2">
        <svg class="mt-0.5 h-4 w-4 flex-shrink-0 {{ $destructive ? 'text-amber-600' : 'text-indigo-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            @if($destructive)
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            @else
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            @endif
        </svg>
        <div class="flex-1">
            <p id="confirm-{{ $messageId }}-title" class="text-xs font-semibold {{ $destructive ? 'text-amber-900' : 'text-indigo-900' }}">{{ $title }}</p>
            <p id="confirm-{{ $messageId }}-body" class="mt-0.5 text-[11px] {{ $destructive ? 'text-amber-800' : 'text-indigo-800' }}">{{ $body }}</p>
        </div>
    </div>

    <div class="mt-2 flex gap-2">
        <button type="button"
            wire:click="handleArtifactConfirm('{{ $messageId }}')"
            wire:confirm="{{ $body }}"
            class="rounded-md px-2.5 py-1 text-[11px] font-medium text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 {{ $destructive ? 'bg-amber-600 hover:bg-amber-700 focus-visible:ring-amber-500' : 'bg-indigo-600 hover:bg-indigo-700 focus-visible:ring-indigo-500' }}"
        >
            {{ $confirmLabel }}
        </button>
        <button type="button"
            wire:click="handleArtifactDismiss('{{ $messageId }}')"
            class="rounded-md border border-gray-300 bg-white px-2.5 py-1 text-[11px] font-medium text-gray-700 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-400"
        >
            {{ $cancelLabel }}
        </button>
    </div>
</div>
