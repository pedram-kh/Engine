@component('mail::message')
{{ trans('auth.mfa_admin.reset.greeting', ['name' => $recipientName]) }}

{{ trans('auth.mfa_admin.reset.body', ['app' => $appName]) }}

{{ trans('auth.mfa_admin.reset.next') }}

@component('mail::button', ['url' => $signInUrl])
{{ trans('auth.mfa_admin.reset.cta', ['app' => $appName]) }}
@endcomponent

**{{ trans('auth.mfa_admin.reset.unexpected') }}**

— {{ $appName }}
@endcomponent
