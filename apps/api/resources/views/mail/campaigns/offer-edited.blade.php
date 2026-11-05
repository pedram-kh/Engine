@component('mail::message')
{{ trans('campaigns.assignment_notifications.offer_edited.email.greeting', ['name' => $creatorName]) }}

{{ trans('campaigns.assignment_notifications.offer_edited.email.body', ['campaign' => $campaignName, 'previous' => $previousAmount, 'new' => $newAmount]) }}

@component('mail::button', ['url' => $assignmentUrl])
{{ trans('campaigns.assignment_notifications.offer_edited.email.cta') }}
@endcomponent

— {{ config('app.name') }}
@endcomponent
