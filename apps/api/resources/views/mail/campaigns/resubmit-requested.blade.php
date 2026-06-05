@component('mail::message')
{{ trans('campaigns.assignment_notifications.resubmit_requested.email.greeting', ['name' => $creatorName]) }}

{{ trans('campaigns.assignment_notifications.resubmit_requested.email.body_' . $mode, ['campaign' => $campaignName]) }}

@if (! empty($feedback))
**{{ trans('campaigns.assignment_notifications.resubmit_requested.email.feedback_label') }}:**

> {{ $feedback }}
@endif

@component('mail::button', ['url' => $assignmentUrl])
{{ trans('campaigns.assignment_notifications.resubmit_requested.email.cta') }}
@endcomponent

— {{ config('app.name') }}
@endcomponent
