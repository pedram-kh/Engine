@component('mail::message')
{{ trans('auth.email_verification.greeting', ['name' => $user->name]) }}

{{ trans('auth.email_verification.body', ['app' => $appName, 'hours' => $expiresInHours]) }}

@component('mail::button', ['url' => $verifyUrl])
{{ trans('auth.email_verification.cta') }}
@endcomponent

{{ trans('auth.email_verification.ignore') }}

— {{ $appName }}
@endcomponent
