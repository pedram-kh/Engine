@component('mail::message')
{{ trans('creators.invitations.email.greeting') }}

{{ trans('creators.invitations.email.body', ['agency' => $agencyName]) }}

@component('mail::button', ['url' => $acceptUrl])
{{ trans('creators.invitations.email.cta') }}
@endcomponent

{{ trans('creators.invitations.email.expiry', ['date' => $expiresAt]) }}

{{ trans('creators.invitations.email.ignore') }}

— {{ config('app.name') }}
@endcomponent
