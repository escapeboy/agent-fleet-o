{{-- Reopens the cookie banner so the visitor can withdraw (Reject) or give consent. Renders only where
     the GA tag does. Pass classes with class="...". --}}
@if(config('services.google_analytics.id'))
    <button type="button" data-cookie-settings {{ $attributes->merge(['class' => 'underline']) }}>Cookie settings</button>
@endif
