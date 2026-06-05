@component('mail::message')
{{ trans('campaigns.assignment_notifications.contract_attached.email.greeting', ['name' => $creatorName]) }}

{{ trans('campaigns.assignment_notifications.contract_attached.email.body', ['campaign' => $campaignName]) }}

@component('mail::button', ['url' => $reviewUrl])
{{ trans('campaigns.assignment_notifications.contract_attached.email.cta') }}
@endcomponent

— {{ config('app.name') }}
@endcomponent
