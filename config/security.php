<?php

return [
    'enabled' => env('SECURITY_HEADERS_ENABLED', true),

    // Only exact proxy IPs/CIDRs verified by the hosting administrator. Never trust '*'.
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),

    // Opt in only after verifying TLS and proxy forwarding on the real host.
    'hsts' => [
        'enabled' => env('SECURITY_HSTS_ENABLED', false),
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 300),
    ],

    'csp' => [
        // Keep observation as the default until all authenticated workflows pass.
        'report_only' => env('SECURITY_CSP_REPORT_ONLY', true),
        'directives' => [
            'default-src' => ["'self'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
            // Inspection report/print preview uses a same-origin iframe.
            'frame-src' => ["'self'"],
            'frame-ancestors' => ["'self'"],
            'form-action' => ["'self'"],
            // Existing layouts/app.blade.php CDN scripts; no generic CDN permission.
            'script-src' => [
                "'self'",
                'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
                'https://cdn.jsdelivr.net/npm/sweetalert2@11.26.25/dist/sweetalert2.min.js',
            ],
            'script-src-attr' => ["'none'"],
            'style-src' => [
                "'self'",
                'https://fonts.googleapis.com',
                'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
                'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css',
            ],
            // SweetAlert2 11.26.25's Safari close workaround uses this exact static style.
            // Permit only that hash; application style attributes remain disallowed.
            'style-src-attr' => ["'unsafe-hashes'", "'sha256-".base64_encode(hash('sha256', 'display:none !important', true))."'"],
            'font-src' => [
                "'self'",
                'https://fonts.gstatic.com',
                'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/',
            ],
            // Upload previews use FileReader/object URLs; Bootstrap CSS embeds SVG data URLs.
            'img-src' => ["'self'", 'data:', 'blob:'],
            'connect-src' => ["'self'"],
            'media-src' => ["'self'"],
            'worker-src' => ["'none'"],
            'manifest-src' => ["'self'"],
            // Same-origin, stateless, rate-limited collector; no third-party telemetry.
            'report-uri' => ['/security/csp-reports'],
        ],
    ],

    // Preserve camera/photo capture, map location, clipboard and board fullscreen on this origin.
    'permissions_policy' => 'accelerometer=(), autoplay=(self), camera=(self), geolocation=(self), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=(), fullscreen=(self), clipboard-read=(self), clipboard-write=(self)',
];
