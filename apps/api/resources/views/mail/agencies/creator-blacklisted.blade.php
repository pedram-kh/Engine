@component('mail::message')
{{ trans('creators.blacklisted.email.greeting', ['name' => $displayName]) }}

{{ trans('creators.blacklisted.email.body', ['agency' => $agencyName]) }}

{{ trans('creators.blacklisted.email.closing') }}

— {{ config('app.name') }}
@endcomponent
