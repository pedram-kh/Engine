@component('mail::message')
{{ trans('auth.mfa_admin.exempted.greeting', ['name' => $recipientName]) }}

{{ trans('auth.mfa_admin.exempted.body', ['app' => $appName]) }}

{{ trans('auth.mfa_admin.exempted.still_enrolled') }}

{{ trans('auth.mfa_admin.exempted.unexpected') }}

— {{ $appName }}
@endcomponent
