<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('mock-vendor.stripe.title') }}</title>
    <meta name="robots" content="noindex,nofollow">
    <style>
        body { font-family: system-ui, sans-serif; max-width: 480px; margin: 4rem auto; padding: 0 1rem; }
        button { display: block; width: 100%; padding: 0.75rem; margin: 0.5rem 0; cursor: pointer; }
        .session { font-family: monospace; font-size: 0.75rem; color: #666; }
    </style>
</head>
<body>
    <h1>{{ __('mock-vendor.stripe.title') }}</h1>
    <p>{{ __('mock-vendor.stripe.description') }}</p>
    <p class="session" data-test="session-id">{{ $sessionId }}</p>

    <form method="POST" action="{{ url('/_mock-vendor/stripe/'.$sessionId.'/complete') }}">
        @csrf
        <button type="submit" name="outcome" value="success" data-test="stripe-success">{{ __('mock-vendor.stripe.success') }}</button>
        <button type="submit" name="outcome" value="cancel" data-test="stripe-fail">{{ __('mock-vendor.stripe.fail') }}</button>
    </form>
</body>
</html>
