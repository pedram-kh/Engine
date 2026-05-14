<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('mock-vendor.kyc.title') }}</title>
    <meta name="robots" content="noindex,nofollow">
    <style>
        body { font-family: system-ui, sans-serif; max-width: 480px; margin: 4rem auto; padding: 0 1rem; }
        button { display: block; width: 100%; padding: 0.75rem; margin: 0.5rem 0; cursor: pointer; }
        .session { font-family: monospace; font-size: 0.75rem; color: #666; }
    </style>
</head>
<body>
    <h1>{{ __('mock-vendor.kyc.title') }}</h1>
    <p>{{ __('mock-vendor.kyc.description') }}</p>
    <p class="session" data-test="session-id">{{ $sessionId }}</p>

    <form method="POST" action="{{ url('/_mock-vendor/kyc/'.$sessionId.'/complete') }}">
        @csrf
        <button type="submit" name="outcome" value="success" data-test="kyc-success">{{ __('mock-vendor.kyc.success') }}</button>
        <button type="submit" name="outcome" value="fail" data-test="kyc-fail">{{ __('mock-vendor.kyc.fail') }}</button>
        <button type="submit" name="outcome" value="cancel" data-test="kyc-cancel">{{ __('mock-vendor.kyc.cancel') }}</button>
    </form>
</body>
</html>
