<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
@if(!empty($emailTheme) && $emailTheme->logo_url)
<img src="{{ $emailTheme->logo_url }}" alt="{{ $emailTheme->company_name ?? config('app.name') }}" width="{{ $emailTheme->logo_width ?? 150 }}" style="max-height:60px;object-fit:contain;">
@else
{{ config('app.name') }}
@endif
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
@if(!empty($emailTheme) && $emailTheme->footer_text)
{!! nl2br(e($emailTheme->footer_text)) !!}
@else
&copy; {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}
@endif
@if(!empty($emailTheme) && $emailTheme->company_name)
<br>{{ $emailTheme->company_name }}@if($emailTheme->company_address)<br>{{ $emailTheme->company_address }}@endif
@endif
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
