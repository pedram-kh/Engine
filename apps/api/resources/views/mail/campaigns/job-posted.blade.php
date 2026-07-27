@component('mail::message')
{{ trans('campaigns.job_posted.greeting', ['name' => $user->name]) }}

{{ trans('campaigns.job_posted.body', ['agency' => $agencyName, 'campaign' => $campaignName]) }}

@component('mail::button', ['url' => $actionUrl])
{{ trans('campaigns.job_posted.cta') }}
@endcomponent

{{ trans('campaigns.job_posted.ignore') }}

— {{ $appName }}
@endcomponent
