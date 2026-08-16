@component('mail::message')
{{ trans('campaigns.assignment_notifications.reviewed.email.greeting', ['name' => $creatorName]) }}

@if ($roundLabel !== null)
**{{ $roundLabel }}**
@endif

{{ trans('campaigns.assignment_notifications.reviewed.email.body_' . $outcome, ['campaign' => $campaignName]) }}

@if (! empty($feedback))
**{{ trans('campaigns.assignment_notifications.reviewed.email.feedback_label') }}:**

> {{ $feedback }}
@endif

@component('mail::button', ['url' => $assignmentUrl])
{{ trans('campaigns.assignment_notifications.reviewed.email.cta') }}
@endcomponent

— {{ config('app.name') }}
@endcomponent
