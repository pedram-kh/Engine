@component('mail::message')
{{ trans('campaigns.campaign_application.submitted.greeting', ['name' => $recipientName]) }}

{{ trans('campaigns.campaign_application.submitted.body', ['creator' => $creatorName, 'campaign' => $campaignName]) }}

@component('mail::button', ['url' => $actionUrl])
{{ trans('campaigns.campaign_application.submitted.cta') }}
@endcomponent

— {{ $appName }}
@endcomponent
