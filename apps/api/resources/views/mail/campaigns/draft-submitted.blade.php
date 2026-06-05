@component('mail::message')
{{ trans('campaigns.assignment_notifications.draft_submitted.email.greeting', ['name' => $recipientName]) }}

{{ trans('campaigns.assignment_notifications.draft_submitted.email.body', ['creator' => $creatorName, 'campaign' => $campaignName]) }}

@component('mail::button', ['url' => $reviewUrl])
{{ trans('campaigns.assignment_notifications.draft_submitted.email.cta') }}
@endcomponent

— {{ config('app.name') }}
@endcomponent
