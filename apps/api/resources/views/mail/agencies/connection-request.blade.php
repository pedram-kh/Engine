@component('mail::message')
{{ trans('creators.connection_request.email.greeting', ['name' => $displayName]) }}

{{ trans('creators.connection_request.email.body', ['agency' => $agencyName]) }}

@component('mail::button', ['url' => $dashboardUrl])
{{ trans('creators.connection_request.email.cta') }}
@endcomponent

{{ trans('creators.connection_request.email.ignore') }}

— {{ config('app.name') }}
@endcomponent
