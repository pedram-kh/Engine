@component('mail::message')
{{ trans('campaigns.assignment_notifications.verification_failed.email.greeting', ['name' => $recipientName]) }}

{{ trans('campaigns.assignment_notifications.verification_failed.email.body', ['creator' => $creatorName, 'campaign' => $campaignName]) }}

**{{ trans('campaigns.assignment_notifications.verification_failed.email.reason_label') }}:**
{{ trans('campaigns.assignment_notifications.verification_failed.email.reason_' . $outcome) }}

@component('mail::button', ['url' => $reviewUrl])
{{ trans('campaigns.assignment_notifications.verification_failed.email.cta') }}
@endcomponent

— {{ config('app.name') }}
@endcomponent
