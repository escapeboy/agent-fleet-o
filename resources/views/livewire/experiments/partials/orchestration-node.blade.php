@php
    $isCurrent = $node->id === $currentId;
    $statusColors = [
        'draft' => 'bg-gray-100 text-gray-700',
        'scoring' => 'bg-blue-100 text-blue-700',
        'planning' => 'bg-blue-100 text-blue-700',
        'building' => 'bg-blue-100 text-blue-700',
        'executing' => 'bg-indigo-100 text-indigo-700',
        'awaiting_children' => 'bg-purple-100 text-purple-700',
        'awaiting_approval' => 'bg-yellow-100 text-yellow-700',
        'collecting_metrics' => 'bg-teal-100 text-teal-700',
        'evaluating' => 'bg-teal-100 text-teal-700',
        'completed' => 'bg-green-100 text-green-700',
        'killed' => 'bg-red-100 text-red-700',
        'paused' => 'bg-yellow-100 text-yellow-700',
    ];
    $color = $statusColors[$node->status->value] ?? 'bg-gray-100 text-gray-700';
@endphp

<div class="flex items-center gap-2 rounded-md px-2 py-1.5 {{ $isCurrent ? 'bg-primary-50 ring-1 ring-primary-200' : 'hover:bg-gray-50' }}" style="margin-left: {{ $depth * 1.5 }}rem">
    {{-- Connector line --}}
    @if($depth > 0)
        <span class="text-gray-300">&#x2514;</span>
    @endif

    {{-- Status badge --}}
    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $color }}">
        {{ str_replace('_', ' ', ucfirst($node->status->value)) }}
    </span>

    {{-- Title as link --}}
    @if($isCurrent)
        <span class="text-sm font-medium text-gray-900">{{ Str::limit($node->title, 50) }}</span>
    @else
        <a href="{{ route('experiments.show', $node) }}" class="text-sm font-medium text-primary-600 hover:text-primary-700 hover:underline">
            {{ Str::limit($node->title, 50) }}
        </a>
    @endif

    {{-- Budget --}}
    @if($node->budget_cap_credits)
        <span class="text-xs text-gray-400">
            {{ number_format($node->budget_spent_credits) }}/{{ number_format($node->budget_cap_credits) }} credits
        </span>
    @endif

    {{-- Depth indicator --}}
    @if($node->nesting_depth > 0)
        <span class="text-xs text-gray-400">L{{ $node->nesting_depth }}</span>
    @endif
</div>

{{-- Recursively render children --}}
@if($node->relationLoaded('children') && $node->children->isNotEmpty())
    @foreach($node->children as $child)
        @include('livewire.experiments.partials.orchestration-node', ['node' => $child, 'depth' => $depth + 1, 'currentId' => $currentId])
    @endforeach
@endif
