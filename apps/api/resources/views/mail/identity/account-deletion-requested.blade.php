@component('mail::message')
{{ trans('auth.deletion_mail.greeting', ['name' => $recipientName]) }}

{{ trans('auth.deletion_mail.body', ['app' => $appName, 'date' => $date]) }}

{{ trans('auth.deletion_mail.keep') }}

**{{ trans('auth.deletion_mail.unexpected') }}**

{{ trans('auth.deletion_mail.support', ['support' => $support]) }}

— {{ $appName }}
@endcomponent
