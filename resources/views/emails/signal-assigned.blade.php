@component('mail::message')
# Signal Assigned to You

**{{ $actor->name }}** has assigned a signal to you.

@if($reason)
**Note:** {{ $reason }}
@endif

---

**Source:** {{ ucfirst(str_replace('_', ' ', $signal->source_type)) }}
**From:** {{ $signal->source_identifier }}
**Received:** {{ $signal->created_at->format('Y-m-d H:i') }} UTC

@component('mail::button', ['url' => $signalUrl])
View Signals
@endcomponent

@endcomponent
