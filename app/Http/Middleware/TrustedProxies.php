<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

class TrustedProxies extends TrustProxies
{
    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO;

    protected function setTrustedProxyIpAddresses(Request $request)
    {
        // Avoid the framework's platform/hostname-based fallback to trusting every proxy.
        $request->setTrustedProxies($this->proxies(), $this->headers);
    }

    protected function proxies()
    {
        // Read after Laravel loads configuration, including when config is cached.
        // An invalid entry must never expand trust to arbitrary callers.
        return array_values(array_filter(config('security.trusted_proxies', []), function (string $proxy): bool {
            $parts = explode('/', $proxy, 2);
            if (! filter_var($parts[0], FILTER_VALIDATE_IP)) {
                return false;
            }

            return ! isset($parts[1]) || (ctype_digit($parts[1]) && (int) $parts[1] <= (str_contains($parts[0], ':') ? 128 : 32));
        }));
    }
}
