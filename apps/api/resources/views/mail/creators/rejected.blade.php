@component('mail::message')
{{ trans('creators.rejected.email.greeting', ['name' => $displayName]) }}

{{ trans('creators.rejected.email.body') }}

**{{ trans('creators.rejected.email.reason_label') }}**

> {{ $rejectionReason }}

{{ trans('creators.rejected.email.resubmit_hint') }}

@component('mail::button', ['url' => $dashboardUrl])
{{ trans('creators.rejected.email.cta') }}
@endcomponent

— {{ config('app.name') }}
@endcomponent
