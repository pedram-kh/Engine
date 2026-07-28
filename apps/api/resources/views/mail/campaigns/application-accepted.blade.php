@component('mail::message')
{{ trans('campaigns.campaign_application.accepted.greeting', ['name' => $creatorName]) }}

{{ trans('campaigns.campaign_application.accepted.body', ['agency' => $agencyName, 'campaign' => $campaignName]) }}

@component('mail::button', ['url' => $actionUrl])
{{ trans('campaigns.campaign_application.accepted.cta') }}
@endcomponent

— {{ $appName }}
@endcomponent
