@component('mail::message')
{{ trans('messages.digest.greeting', ['name' => $recipientName]) }}

{{ trans('messages.digest.intro', ['count' => $totalUnread, 'threads' => $threadCount]) }}

@foreach ($lines as $line)
- {{ trans('messages.digest.thread_line', ['campaign' => $line['campaign'], 'counterparty' => $line['counterparty'], 'count' => $line['unread']]) }}
@endforeach

@component('mail::button', ['url' => $messagesUrl])
{{ trans('messages.digest.cta') }}
@endcomponent

— {{ config('app.name') }}
@endcomponent
