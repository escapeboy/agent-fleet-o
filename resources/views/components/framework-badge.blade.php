@props(['framework'])

@php
    $enum = $framework instanceof \App\Domain\Skill\Enums\Framework
        ? $framework
        : \App\Domain\Skill\Enums\Framework::tryFrom((string) $framework);
@endphp

@if ($enum)
    <span
        {{ $attributes->merge(['class' => 'inline-flex items-center rounded-md bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-700/10']) }}
        title="{{ $enum->description() }}"
    >
        {{ $enum->label() }}
    </span>
@endif
