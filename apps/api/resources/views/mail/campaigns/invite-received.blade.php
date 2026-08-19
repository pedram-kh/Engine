@component('mail::message')
{{ trans('campaigns.assignment_notifications.invite_received.email.greeting', ['name' => $creatorName]) }}

{{ trans('campaigns.assignment_notifications.invite_received.email.body_' . $outcome, ['campaign' => $campaignName]) }}

@component('mail::button', ['url' => $assignmentUrl])
{{ trans('campaigns.assignment_notifications.invite_received.email.cta') }}
@endcomponent

— {{ config('app.name') }}
@endcomponent
