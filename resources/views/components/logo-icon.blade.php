{{--
    FleetQ logo icon — fleet V-formation
    5 agent nodes in a V-formation with connecting lines.
    Renders via currentColor so it adapts to any text color.
    Usage: <x-logo-icon class="h-5 w-5 text-white" />
--}}
<svg {{ $attributes->merge(['viewBox' => '0 0 24 24', 'fill' => 'none', 'aria-hidden' => 'true', 'xmlns' => 'http://www.w3.org/2000/svg']) }}>
    {{-- Formation lines --}}
    <path stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"
          d="M12 3.5 L7.5 10 M12 3.5 L16.5 10 M7.5 10 L2.5 18 M16.5 10 L21.5 18"/>
    {{-- Cross-wing coordinator (dashed) --}}
    <path stroke="currentColor" stroke-width="0.85" stroke-linecap="round" stroke-dasharray="2 1.75"
          d="M7.5 10 L16.5 10"/>
    {{-- Agent nodes --}}
    <circle cx="12"   cy="3.5"  r="2.5"  fill="currentColor"/>
    <circle cx="7.5"  cy="10"   r="1.75" fill="currentColor"/>
    <circle cx="16.5" cy="10"   r="1.75" fill="currentColor"/>
    <circle cx="2.5"  cy="18"   r="1.5"  fill="currentColor"/>
    <circle cx="21.5" cy="18"   r="1.5"  fill="currentColor"/>
</svg>
