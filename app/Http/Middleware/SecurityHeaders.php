<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Generate before rendering, and reset Vite's nonce on every request (including long-lived workers).
        $request->attributes->set('csp_nonce', base64_encode(random_bytes(32)));
        Vite::useCspNonce($request->attributes->get('csp_nonce'));

        return $this->apply($request, $next($request));
    }

    public function apply(Request $request, Response $response): Response
    {
        if (! config('security.enabled')) {
            return $response;
        }

        // set() replaces existing application values, including MediaController's nosniff.
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', config('security.permissions_policy'));

        $response->headers->remove('Strict-Transport-Security');
        if (config('security.hsts.enabled') && app()->environment('production') && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.max(0, (int) config('security.hsts.max_age')));
        }

        // One application policy only. The exception callback may also pass through here.
        $response->headers->remove('Content-Security-Policy');
        $response->headers->remove('Content-Security-Policy-Report-Only');
        $header = config('security.csp.report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
        $response->headers->set($header, $this->contentSecurityPolicy($request));

        return $response;
    }

    private function contentSecurityPolicy(Request $request): string
    {
        $directives = config('security.csp.directives');
        if ($nonce = $request->attributes->get('csp_nonce')) {
            foreach (['script-src', 'style-src'] as $directive) {
                $directives[$directive][] = "'nonce-{$nonce}'";
            }
        }

        // Derive the actual dev-server origin from Vite, never from request headers.
        // Production deliberately never permits HMR origins or WebSockets.
        if (app()->environment('local') && Vite::isRunningHot()) {
            $url = parse_url(Vite::asset('resources/js/app.js'));
            if (isset($url['scheme'], $url['host']) && in_array($url['scheme'], ['http', 'https'], true)) {
                $authority = $url['host'].(isset($url['port']) ? ':'.$url['port'] : '');
                $origin = $url['scheme'].'://'.$authority;
                $directives['script-src'][] = $origin;
                $directives['style-src'][] = $origin;
                $directives['connect-src'][] = $origin;
                $directives['connect-src'][] = ($url['scheme'] === 'https' ? 'wss' : 'ws').'://'.$authority;
            }
        }

        return implode('; ', array_map(
            fn (string $name, array $sources): string => $name.' '.implode(' ', array_unique($sources)),
            array_keys($directives),
            array_values($directives),
        ));
    }
}
