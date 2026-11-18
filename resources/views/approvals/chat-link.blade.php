@php
    $context = $approval->context ?? [];
    $title = $context['experiment_title'] ?? 'Approval request';
    $summary = $context['ai_summary']['summary'] ?? null;
    $label = $decision === 'approve' ? 'Approve' : 'Reject';
@endphp

<x-layouts.auth :title="$label.' approval'">
    <div class="w-full max-w-md rounded-lg bg-white p-6 shadow">
        <h1 class="text-lg font-semibold text-gray-900">{{ $title }}</h1>

        @if (is_string($summary) && $summary !== '')
            <p class="mt-2 text-sm text-gray-600">{{ $summary }}</p>
        @endif

        @if ($alreadyDecided)
            <p class="mt-4 rounded bg-gray-100 p-3 text-sm text-gray-700">
                This approval was already decided (status: {{ $approval->status->value }}).
            </p>
        @elseif ($outcome !== null)
            <p class="mt-4 rounded bg-gray-100 p-3 text-sm text-gray-700">
                @if ($outcome === 'pending')
                    Your approval was recorded. More approvals are needed before this request is approved.
                @else
                    Done. This request is now {{ $outcome }}.
                @endif
            </p>
        @else
            <p class="mt-4 text-sm text-gray-700">
                You are about to <strong>{{ strtolower($label) }}</strong> this request.
            </p>
            <form method="POST" action="{{ request()->fullUrl() }}" class="mt-4">
                @csrf
                <button type="submit"
                        class="rounded px-4 py-2 text-sm font-medium text-white {{ $decision === 'approve' ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700' }}">
                    Confirm {{ strtolower($label) }}
                </button>
            </form>
        @endif

        <a href="{{ route('approvals.index') }}" class="mt-6 inline-block text-sm text-blue-600 underline">
            Open approvals inbox
        </a>
    </div>
</x-layouts.auth>
