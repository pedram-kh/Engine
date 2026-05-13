@component('mail::message')
{{ trans('invitations.email.greeting', ['name' => $inviteeName]) }}

{{ trans('invitations.email.body', [
    'agency' => $agencyName,
    'role' => $roleLabel,
    'inviter' => $inviterName,
    'days' => $expiresInDays,
]) }}

@component('mail::button', ['url' => $acceptUrl])
{{ trans('invitations.email.cta') }}
@endcomponent

{{ trans('invitations.email.ignore') }}

— {{ $appName }}
@endcomponent
