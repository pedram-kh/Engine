@component('mail::message')
{{ trans('creators.admin_connected.email.greeting', ['name' => $displayName]) }}

{{ trans('creators.admin_connected.email.body', ['agency' => $agencyName]) }}

@component('mail::button', ['url' => $dashboardUrl])
{{ trans('creators.admin_connected.email.cta') }}
@endcomponent

{{ trans('creators.admin_connected.email.unexpected') }}

— {{ config('app.name') }}
@endcomponent
