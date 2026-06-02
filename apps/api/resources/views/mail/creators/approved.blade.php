@component('mail::message')
{{ trans('creators.approved.email.greeting', ['name' => $displayName]) }}

{{ trans('creators.approved.email.body') }}

@if ($welcomeMessage !== null)
> {{ $welcomeMessage }}
@endif

@component('mail::button', ['url' => $dashboardUrl])
{{ trans('creators.approved.email.cta') }}
@endcomponent

— {{ config('app.name') }}
@endcomponent
