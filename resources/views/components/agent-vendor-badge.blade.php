@props([
    'provider' => null,
    'model' => null,
    'size' => 'sm',
])

@php
    $providerKey = (string) ($provider ?? 'unknown');

    $vendors = [
        'anthropic' => [
            'label' => 'Anthropic',
            'monogram' => 'A',
            'icon' => null,
            'pillClass' => 'bg-orange-50 text-orange-700 ring-orange-600/20',
            'iconClass' => 'bg-orange-600 text-white',
        ],
        'openai' => [
            'label' => 'OpenAI',
            'monogram' => 'O',
            'icon' => null,
            'pillClass' => 'bg-gray-100 text-gray-800 ring-gray-700/20',
            'iconClass' => 'bg-gray-900 text-white',
        ],
        'google' => [
            'label' => 'Google',
            'monogram' => null,
            'icon' => 'fa-brands fa-google',
            'pillClass' => 'bg-blue-50 text-blue-700 ring-blue-600/20',
            'iconClass' => 'text-blue-600',
        ],
        'groq' => [
            'label' => 'Groq',
            'monogram' => null,
            'icon' => 'fa-solid fa-bolt',
            'pillClass' => 'bg-violet-50 text-violet-700 ring-violet-600/20',
            'iconClass' => 'text-violet-600',
        ],
        'openrouter' => [
            'label' => 'OpenRouter',
            'monogram' => null,
            'icon' => 'fa-solid fa-route',
            'pillClass' => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
            'iconClass' => 'text-indigo-600',
        ],
        'mistral' => [
            'label' => 'Mistral',
            'monogram' => 'M',
            'icon' => null,
            'pillClass' => 'bg-red-50 text-red-700 ring-red-600/20',
            'iconClass' => 'bg-red-600 text-white',
        ],
        'claude-code' => [
            'label' => 'Claude Code',
            'monogram' => null,
            'icon' => 'fa-solid fa-laptop-code',
            'pillClass' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            'iconClass' => 'text-emerald-600',
        ],
        'claude-code-vps' => [
            'label' => 'Claude Code (VPS)',
            'monogram' => null,
            'icon' => 'fa-solid fa-server',
            'pillClass' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            'iconClass' => 'text-emerald-600',
        ],
        'codex' => [
            'label' => 'Codex',
            'monogram' => null,
            'icon' => 'fa-solid fa-terminal',
            'pillClass' => 'bg-slate-100 text-slate-700 ring-slate-600/20',
            'iconClass' => 'text-slate-700',
        ],
    ];

    $vendor = $vendors[$providerKey] ?? [
        'label' => ucfirst($providerKey),
        'monogram' => null,
        'icon' => 'fa-solid fa-microchip',
        'pillClass' => 'bg-gray-100 text-gray-700 ring-gray-500/20',
        'iconClass' => 'text-gray-500',
    ];

    $isCompact = $size === 'sm';
    $pillPad = $isCompact ? 'px-1.5 py-0.5 text-xs' : 'px-2 py-1 text-sm';
    $iconBox = $isCompact ? 'h-4 w-4 text-[10px]' : 'h-5 w-5 text-xs';
@endphp

<span
    {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 rounded-md ring-1 ring-inset font-medium {$vendor['pillClass']} {$pillPad}"]) }}
    title="{{ $vendor['label'] }}{{ $model ? ' / '.$model : '' }}"
>
    @if(!empty($vendor['monogram']))
        <span class="inline-flex items-center justify-center rounded-sm font-bold {{ $iconBox }} {{ $vendor['iconClass'] }}">
            {{ $vendor['monogram'] }}
        </span>
    @elseif(!empty($vendor['icon']))
        <i class="{{ $vendor['icon'] }} {{ $vendor['iconClass'] }}" aria-hidden="true"></i>
    @endif
    <span>{{ $vendor['label'] }}</span>
    @if($model)
        <span class="text-gray-400">/</span>
        <span class="font-normal opacity-80">{{ $model }}</span>
    @endif
</span>
