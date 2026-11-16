@php
    $productCode = strtoupper((string) $productCode);
    $productName = match ($productCode) {
        'EDUNEXA' => 'EduNexa',
        'MEDIFLOW' => 'MediFlow',
        default => $productCode ?: 'product',
    };
    $heading = $renewalRequired ? 'Your '.$productName.' license needs attention.' : 'Verify your '.$productName.' license.';
    $message = $renewalRequired
        ? 'Access to this application is paused because the license period or verification grace has ended.'
        : $renewalMessage;
    $baseRouteName = (string) (config('kernelbridge-licensing.redirects.activation_route') ?: (config('kernelbridge-licensing.routes.name', 'kernelbridge.license.').'show'));
    $activationRoute = str_ends_with($baseRouteName, '.show') ? substr($baseRouteName, 0, -5).'.show' : $baseRouteName;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0b3d6e">
    <title>License renewal · KernelBridge</title>
    <style>
        :root { color-scheme: light; --navy: #0b3157; --blue: #0b3d6e; --teal: #0797a5; --ink: #17324d; --muted: #62758a; --line: #dce6ee; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; color: var(--ink); background: radial-gradient(ellipse at 7% 6%, rgba(7,151,165,.12), transparent 30rem), radial-gradient(ellipse at 96% 94%, rgba(11,61,110,.10), transparent 32rem), #f3f7fa; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .page { display: grid; min-height: 100vh; place-items: center; padding: 28px 16px; }
        .panel { width: min(100%, 620px); padding: clamp(24px, 6vw, 48px); border: 1px solid rgba(11,61,110,.12); border-radius: 18px; background: #fff; box-shadow: 0 24px 65px rgba(23,50,77,.12); }
        .brand { display: inline-flex; align-items: center; gap: 12px; color: var(--navy); font-size: 17px; font-weight: 800; text-decoration: none; }
        .brand img { width: 44px; height: 44px; object-fit: contain; }
        .brand small { display: block; margin-top: 3px; color: var(--muted); font-size: 10px; font-weight: 700; }
        .eyebrow { margin: 35px 0 8px; color: #087e8b; font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        h1 { margin: 0; color: var(--navy); font-size: clamp(30px, 6vw, 42px); line-height: 1.12; }
        .message { margin: 16px 0 0; color: var(--muted); font-size: 15px; line-height: 1.7; }
        .expires { margin-top: 12px; color: #35536e; font-size: 13px; }
        .actions { display: grid; gap: 12px; margin-top: 28px; }
        .action { display: flex; min-height: 50px; align-items: center; justify-content: center; padding: 12px 16px; border: 1px solid var(--blue); border-radius: 8px; color: var(--blue); font-size: 14px; font-weight: 750; text-align: center; text-decoration: none; }
        .action-primary { color: #fff; background: linear-gradient(100deg, var(--blue), #087f91); }
        .return { display: block; margin: 20px auto 0; color: #42627d; font-size: 13px; font-weight: 700; text-align: center; }
        @media (prefers-reduced-motion: no-preference) { .panel { animation: enter .35s ease-out both; } @keyframes enter { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } } }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { scroll-behavior: auto !important; transition-duration: .01ms !important; } }
    </style>
</head>
<body>
    <main class="page">
        <section class="panel" aria-labelledby="renewal-title">
            <a class="brand" href="{{ url('/') }}" aria-label="KernelBridge">
                <img src="{{ asset('vendor/kernelbridge-licensing/kernelbridge-logo.png') }}" alt="KernelBridge">
                <span>KernelBridge<small>Licensing and activation</small></span>
            </a>
            <p class="eyebrow">{{ $renewalRequired ? 'License access paused' : 'License verification required' }}</p>
            <h1 id="renewal-title">{{ $heading }}</h1>
            <p class="message">{{ $message }}</p>
            @if ($state->expires_at && $state->expires_at->isPast())
                <p class="expires">License period ended {{ $state->expires_at->toFormattedDateString() }}.</p>
            @endif
            <nav class="actions" aria-label="KernelBridge license actions">
                <a class="action action-primary" href="{{ $portalUrls['renewal'] }}" target="_blank" rel="noopener noreferrer">Renew license in KernelBridge</a>
                <a class="action" href="{{ $portalUrls['installation_code'] }}" target="_blank" rel="noopener noreferrer">Get an installation code</a>
                <a class="action" href="{{ $portalUrls['activation_code'] }}" target="_blank" rel="noopener noreferrer">Get an activation code</a>
            </nav>
            <a class="return" href="{{ route($activationRoute) }}">Already have a code? Return to activation</a>
        </section>
    </main>
</body>
</html>
