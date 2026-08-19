@component('mail::message')
{{ trans('messages.new_message.greeting', ['name' => $recipientName]) }}

{{ trans('messages.new_message.body_' . $context, ['sender' => $senderName, 'counterparty' => $counterpartyName]) }}

@component('mail::button', ['url' => $threadUrl])
{{ trans('messages.new_message.cta') }}
@endcomponent

— {{ config('app.name') }}
@endcomponent
