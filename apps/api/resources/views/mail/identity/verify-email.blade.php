@component('mail::message')
{{ trans('auth.email_verification.greeting', ['app' => $appName, 'name' => $user->name]) }}

{{ trans('auth.email_verification.body', ['app' => $appName, 'hours' => $expiresInHours]) }}

@component('mail::button', ['url' => $verifyUrl])
{{ trans('auth.email_verification.cta') }}
@endcomponent

{{ trans('auth.email_verification.ignore', ['app' => $appName]) }}

— {{ $appName }}
@endcomponent
