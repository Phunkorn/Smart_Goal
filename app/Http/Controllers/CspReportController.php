<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CspReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if (! config('security.enabled')) {
            return response()->noContent();
        }

        abort_if(strlen($request->getContent()) > 16384, 413);
        $contentType = strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0]));
        abort_unless(in_array($contentType, ['application/json', 'application/csp-report'], true), 415);

        $payload = json_decode($request->getContent(), true, 8);
        $report = is_array($payload) ? ($payload['csp-report'] ?? null) : null;
        abort_unless(is_array($report), 422);
        $directive = $report['effective-directive'] ?? $report['violated-directive'] ?? null;
        abort_unless(is_string($directive) && preg_match('/\A[a-z][a-z-]{0,63}\z/D', $directive), 422);

        // Reports are untrusted. Never log document URLs, query strings, samples, cookies or user identity.
        Log::notice('CSP violation', [
            'directive' => $directive,
            'disposition' => ($report['disposition'] ?? null) === 'enforce' ? 'enforce' : 'report',
        ]);

        return response()->noContent();
    }
}
