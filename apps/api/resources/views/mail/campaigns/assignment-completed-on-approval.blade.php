@component('mail::message')
{{ trans('campaigns.assignment_notifications.completed_on_approval.email.greeting', ['name' => $creatorName]) }}

{{ trans('campaigns.assignment_notifications.completed_on_approval.email.body', ['campaign' => $campaignName]) }}

@component('mail::button', ['url' => $assignmentUrl])
{{ trans('campaigns.assignment_notifications.completed_on_approval.email.cta') }}
@endcomponent

— {{ config('app.name') }}
@endcomponent
