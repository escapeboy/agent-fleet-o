@component('mail::message')
# Experiment Complete: {{ $experiment->title }}

@if($experiment->thesis)
**Thesis:** {{ $experiment->thesis }}
@endif

Completed after **{{ $experiment->current_iteration }}** iteration(s).

---

## Artifacts Built ({{ $artifacts->count() }})

@component('mail::table')
| Name | Type | Version |
|:-----|:-----|:--------|
@foreach($artifacts as $artifact)
| {{ $artifact->name }} | {{ $artifact->type }} | v{{ $artifact->current_version }} |
@endforeach
@endcomponent

@if($metrics->isNotEmpty())
## Key Metrics

@component('mail::table')
| Metric | Value |
|:-------|:------|
@foreach($metrics->take(10) as $metric)
| {{ $metric->name }} | {{ $metric->value }} |
@endforeach
@endcomponent
@endif

@if($stages->isNotEmpty())
## Pipeline Stages

@component('mail::table')
| Stage | Status | Duration |
|:------|:-------|:---------|
@foreach($stages as $stage)
| {{ is_object($stage->stage) ? $stage->stage->value : $stage->stage }} | {{ is_object($stage->status) ? $stage->status->value : $stage->status }} | {{ $stage->completed_at && $stage->started_at ? $stage->started_at->diffForHumans($stage->completed_at, true) : '-' }} |
@endforeach
@endcomponent
@endif

View the full experiment details, download artifacts, and review all results on the experiment page.

@component('mail::button', ['url' => $experimentUrl])
View Experiment Details
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
