<?php

return [
    'deployment' => [
        'mode' => env('KERNELBRIDGE_DEPLOYMENT_MODE', env('APP_MODE', 'client')),
        'allow_local_fallback' => (bool) env('KERNELBRIDGE_ALLOW_LOCAL_FALLBACK', false),
        'strict_profile' => (bool) env('KERNELBRIDGE_STRICT_DEPLOYMENT_PROFILE', true),
    ],
    'api_url' => env('KERNELBRIDGE_API_URL', 'https://kernelbridge.com/api/v1'),
    'product_code' => env('KERNELBRIDGE_PRODUCT_CODE'),
    'api_token' => env('KERNELBRIDGE_API_TOKEN'),
    // Public raw Ed25519 keys, base64-encoded and indexed by KernelBridge key_id.
    'entitlement_public_keys' => json_decode((string) env('KERNELBRIDGE_ENTITLEMENT_PUBLIC_KEYS', '{}'), true) ?: [],
    'legacy_activation_enabled' => true,
    'timeout_seconds' => (int) env('KERNELBRIDGE_API_TIMEOUT', 10),
    'connect_timeout_seconds' => (int) env('KERNELBRIDGE_API_CONNECT_TIMEOUT', 3),
    'verification_interval_minutes' => (int) env('KERNELBRIDGE_VERIFICATION_INTERVAL', 15),
    'verification_queue' => env('REDIS_NOTIFICATION_QUEUE', env('REDIS_QUEUE', 'default')),
    'offline_grace_hours' => (int) env('KERNELBRIDGE_OFFLINE_GRACE_HOURS', 72),
    'signature_key' => env('KERNELBRIDGE_CACHE_SIGNING_KEY', env('APP_KEY')),
    'protection' => [
        'web' => (bool) env('KERNELBRIDGE_PROTECT_WEB_ROUTES', false),
        'except' => array_values(array_filter(array_map('trim', explode(',', (string) env('KERNELBRIDGE_PROTECT_EXCEPT', ''))))),
    ],
    'renewal_notice' => [
        'enabled' => (bool) env('KERNELBRIDGE_RENEWAL_NOTICE_ENABLED', true),
        'threshold_months' => array_values(array_map(
            'intval',
            array_filter(
                explode(',', (string) env('KERNELBRIDGE_RENEWAL_NOTICE_MONTHS', '3,2,1')),
                static fn (string $months): bool => preg_match('/^[1-9]\d*$/', trim($months)) === 1,
            ),
        )),
    ],
    'portal_urls' => [
        'installation_code' => env('KERNELBRIDGE_INSTALLATION_CODE_URL', 'https://kernelbridge.com/account/licenses'),
        'activation_code' => env('KERNELBRIDGE_ACTIVATION_CODE_URL', 'https://kernelbridge.com/account/licenses'),
        'renewal' => env('KERNELBRIDGE_RENEWAL_URL', 'https://kernelbridge.com/account/licenses'),
        'platform_activation' => env('KERNELBRIDGE_PLATFORM_ACTIVATION_URL'),
    ],
    'routes' => [
        'enabled' => (bool) env('KERNELBRIDGE_LICENSE_ROUTES_ENABLED', true),
        'prefix' => env('KERNELBRIDGE_LICENSE_ROUTE_PREFIX', 'license'),
        'name' => env('KERNELBRIDGE_LICENSE_ROUTE_NAME', 'kernelbridge.license.'),
        'middleware' => ['web'],
    ],

    'ui' => [
        'view' => env('KERNELBRIDGE_LICENSE_VIEW', 'kernelbridge-licensing::activation'),
        'logo_path' => env('KERNELBRIDGE_LICENSE_LOGO_PATH', 'vendor/kernelbridge-licensing/kernelbridge-logo.png'),
        'logo_alt' => env('KERNELBRIDGE_LICENSE_LOGO_ALT'),
        'css_path' => env('KERNELBRIDGE_LICENSE_CSS_PATH', 'vendor/kernelbridge-licensing/activation.css'),
        'show_logo' => (bool) env('KERNELBRIDGE_LICENSE_SHOW_LOGO', true),
        'brand_name' => env('KERNELBRIDGE_LICENSE_BRAND_NAME'),
        'brand_subtitle' => env('KERNELBRIDGE_LICENSE_BRAND_SUBTITLE'),
        'heading' => env('KERNELBRIDGE_LICENSE_HEADING'),
        'description' => env('KERNELBRIDGE_LICENSE_DESCRIPTION'),
    ],

    'redirects' => [
        'activation_route' => env('KERNELBRIDGE_LICENSE_ACTIVATION_ROUTE'),
        'renewal_route' => env('KERNELBRIDGE_LICENSE_RENEWAL_ROUTE'),
        'after_platform_link' => env('KERNELBRIDGE_LICENSE_REDIRECT_AFTER_PLATFORM_LINK'),
        'after_activation' => env('KERNELBRIDGE_LICENSE_REDIRECT_AFTER_ACTIVATION', function () {
            $product = strtoupper((string) env('KERNELBRIDGE_PRODUCT_CODE'));

            return in_array($product, ['EDUNEXA', 'MEDIFLOW'], true) ? '/configuration' : '/';
        }),
    ],
];
