@component('mail::message')
{{ trans('creators.incomplete_nudge.finish.greeting', ['name' => $user->name]) }}

{{ trans('creators.incomplete_nudge.finish.body') }}

@component('mail::button', ['url' => $actionUrl])
{{ trans('creators.incomplete_nudge.finish.cta') }}
@endcomponent

{{ trans('creators.incomplete_nudge.finish.ignore') }}

— {{ $appName }}
@endcomponent
