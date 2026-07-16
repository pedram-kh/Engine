@component('mail::message')
{{ trans('creators.incomplete_nudge.verify.greeting', ['name' => $user->name]) }}

{{ trans('creators.incomplete_nudge.verify.body') }}

@component('mail::button', ['url' => $actionUrl])
{{ trans('creators.incomplete_nudge.verify.cta') }}
@endcomponent

{{ trans('creators.incomplete_nudge.verify.expiry', ['hours' => $expiresInHours]) }}

{{ trans('creators.incomplete_nudge.verify.ignore') }}

— {{ $appName }}
@endcomponent
