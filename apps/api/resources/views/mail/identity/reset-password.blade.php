@component('mail::message')
{{ trans('auth.reset.greeting', ['name' => $user->name]) }}

{{ trans('auth.reset.body', ['app' => $appName, 'minutes' => $expiresInMinutes]) }}

@component('mail::button', ['url' => $resetUrl])
{{ trans('auth.reset.cta') }}
@endcomponent

{{ trans('auth.reset.ignore') }}

— {{ $appName }}
@endcomponent
