@component('mail::message')
{{ trans('campaigns.assignment_notifications.contract_accepted.email.greeting', ['name' => $recipientName]) }}

{{ trans('campaigns.assignment_notifications.contract_accepted.email.body', ['creator' => $creatorName, 'campaign' => $campaignName]) }}

@component('mail::button', ['url' => $campaignUrl])
{{ trans('campaigns.assignment_notifications.contract_accepted.email.cta') }}
@endcomponent

— {{ config('app.name') }}
@endcomponent
