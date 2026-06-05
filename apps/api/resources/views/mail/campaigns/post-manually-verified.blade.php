@component('mail::message')
{{ trans('campaigns.assignment_notifications.manually_verified.email.greeting', ['name' => $creatorName]) }}

{{ trans('campaigns.assignment_notifications.manually_verified.email.body', ['campaign' => $campaignName]) }}

@component('mail::button', ['url' => $assignmentUrl])
{{ trans('campaigns.assignment_notifications.manually_verified.email.cta') }}
@endcomponent

— {{ config('app.name') }}
@endcomponent
