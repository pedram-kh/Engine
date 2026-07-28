@component('mail::message')
{{ trans('campaigns.campaign_application.rejected.greeting', ['name' => $creatorName]) }}

{{-- The cause chooses the body variant (`body_agency_rejected` /
     `body_campaign_closed`) — the draft-reviewed `body_ . $outcome` precedent.
     ApplicationRejectionCause is the only writer of $causeKey. --}}
{{ trans('campaigns.campaign_application.rejected.body_' . $causeKey, ['campaign' => $campaignName]) }}

@component('mail::button', ['url' => $actionUrl])
{{ trans('campaigns.campaign_application.rejected.cta') }}
@endcomponent

— {{ $appName }}
@endcomponent
