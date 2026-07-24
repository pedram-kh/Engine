@component('mail::message')
{{ trans('creators.disconnected.email.greeting', ['name' => $recipientName]) }}

{{ trans('creators.disconnected.email.body', ['counterparty' => $counterpartyName]) }}

{{ trans('creators.disconnected.email.unexpected') }}

— {{ config('app.name') }}
@endcomponent
