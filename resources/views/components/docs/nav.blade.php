@props(['current' => ''])

@php
$sections = [
    'Getting Started' => [
        ['slug' => 'introduction',    'label' => 'What is FleetQ?'],
        ['slug' => 'getting-started', 'label' => 'Quick Start (5 min)'],
    ],
    'Core Concepts' => [
        ['slug' => 'experiments',  'label' => 'Experiments'],
        ['slug' => 'agents',       'label' => 'Agents'],
        ['slug' => 'skills',       'label' => 'Skills'],
        ['slug' => 'crews',        'label' => 'Crews'],
        ['slug' => 'workflows',    'label' => 'Workflows'],
        ['slug' => 'projects',     'label' => 'Projects'],
    ],
    'Signals & Automation' => [
        ['slug' => 'signals',  'label' => 'Signals'],
        ['slug' => 'triggers', 'label' => 'Triggers'],
    ],
    'Platform' => [
        ['slug' => 'marketplace',  'label' => 'Marketplace'],
        ['slug' => 'assistant',    'label' => 'AI Assistant'],
    ],
    'Developer' => [
        ['slug' => 'api-reference', 'label' => 'REST API'],
        ['slug' => 'mcp-server',    'label' => 'MCP Server'],
    ],
    'Security & Operations' => [
        ['slug' => 'security',  'label' => 'Security'],
        ['slug' => 'budget',    'label' => 'Budget & Cost'],
        ['slug' => 'audit-log', 'label' => 'Audit Log'],
        ['slug' => 'changelog', 'label' => 'Changelog'],
    ],
];

// Cloud-only pages (appended if routes exist)
if (Route::has('docs.billing')) {
    $sections['Cloud Edition'] = [
        ['slug' => 'billing', 'label' => 'Billing & Plans'],
        ['slug' => 'teams',   'label' => 'Teams & Roles'],
        ['slug' => 'bridge',  'label' => 'FleetQ Bridge'],
    ];
}
@endphp

<div class="px-4 py-6">
    {{-- Logo / heading --}}
    <a href="{{ route('docs.index') }}" class="mb-6 flex items-center gap-2.5 px-2">
        <div class="flex h-7 w-7 items-center justify-center rounded-md bg-primary-600">
            <x-logo-icon class="h-4 w-4 text-white" />
        </div>
        <span class="font-semibold text-gray-900">Docs</span>
    </a>

    @foreach ($sections as $group => $links)
        <div class="mt-6">
            <p class="mb-1 px-2 text-xs font-semibold uppercase tracking-wider text-gray-400">{{ $group }}</p>
            @foreach ($links as $link)
                @php $isActive = $current === $link['slug']; @endphp
                <a href="{{ route('docs.show', $link['slug']) }}"
                   class="flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm transition-colors
                          {{ $isActive
                              ? 'bg-primary-50 font-medium text-primary-700'
                              : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900' }}">
                    @if ($isActive)
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-primary-600" aria-hidden="true"></span>
                    @else
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full" aria-hidden="true"></span>
                    @endif
                    {{ $link['label'] }}
                </a>
            @endforeach
        </div>
    @endforeach

    {{-- Interactive API explorer link --}}
    <div class="mt-6 border-t border-gray-200 pt-4">
        <a href="{{ url('/docs/api') }}"
           target="_blank"
           rel="noopener"
           class="flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm text-gray-600 transition-colors hover:bg-gray-100 hover:text-gray-900">
            <svg class="h-3.5 w-3.5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
            </svg>
            API Explorer
        </a>
    </div>
</div>
