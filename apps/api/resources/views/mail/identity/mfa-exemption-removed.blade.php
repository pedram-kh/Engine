@component('mail::message')
{{ trans('auth.mfa_admin.exemption_removed.greeting', ['name' => $recipientName]) }}

{{ trans('auth.mfa_admin.exemption_removed.body', ['app' => $appName]) }}

{{ trans('auth.mfa_admin.exemption_removed.next') }}

{{ trans('auth.mfa_admin.exemption_removed.unexpected') }}

— {{ $appName }}
@endcomponent
