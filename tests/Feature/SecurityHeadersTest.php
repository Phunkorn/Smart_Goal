<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TrustProxies::flushState();
        config(['security.enabled' => true, 'security.csp.report_only' => true, 'security.hsts.enabled' => false]);
        Route::get('/__security/html', fn () => response('<p>Security test</p>'));
        Route::get('/__security/json', fn () => response()->json(['ok' => true]));
        Route::get('/__security/redirect', fn () => redirect('/login'));
        Route::get('/__security/download', fn () => response()->streamDownload(fn () => print ('export'), 'export.csv'));
        Route::get('/__security/error/{status}', fn (int $status) => abort($status));
        Route::get('/__security/nonce', fn () => response((string) app(\Illuminate\Foundation\Vite::class)(['resources/js/pages/auth/experience.js'])));
        Route::get('/__security/existing', fn () => response('ok', 200, [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Content-Security-Policy-Report-Only' => "default-src 'none'",
            'Strict-Transport-Security' => 'max-age=100; includeSubDomains; preload',
        ]));
    }

    public static function responses(): array
    {
        return [
            'HTML' => ['/__security/html', 200],
            'JSON API' => ['/__security/json', 200],
            'redirect' => ['/__security/redirect', 302],
            'streamed download' => ['/__security/download', 200],
            'forbidden' => ['/__security/error/403', 403],
            'missing route' => ['/__security/missing', 404],
            'CSRF JSON' => ['/__security/error/419', 419],
            'validation' => ['/__security/error/422', 422],
            'throttle' => ['/__security/error/429', 429],
            'server error' => ['/__security/error/500', 500],
            'unavailable' => ['/__security/error/503', 503],
        ];
    }

    #[DataProvider('responses')]
    public function test_headers_cover_response_types_and_errors(string $path, int $status): void
    {
        $response = $this->getJson($path)->assertStatus($status)
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', config('security.permissions_policy'))
            ->assertHeaderMissing('Content-Security-Policy')
            ->assertHeaderMissing('Strict-Transport-Security');

        $policy = $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        foreach (["'unsafe-inline'", "'unsafe-eval'", '*'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $policy);
        }
        $this->assertCount(1, $response->headers->all('X-Content-Type-Options'));
    }

    public function test_nonce_matches_vite_markup_and_changes_between_requests(): void
    {
        $first = $this->get('/__security/nonce')->assertOk();
        preg_match('/nonce="([^"]+)"/', $first->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);
        $this->assertSame(32, strlen(base64_decode($matches[1], true)));
        $this->assertStringContainsString("'nonce-{$matches[1]}'", $first->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertNotSame($first->getContent(), $this->get('/__security/nonce')->getContent());
    }

    public function test_login_vite_tags_receive_the_policy_nonce_without_changing_cache_or_cookies(): void
    {
        config(['session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax']);
        $response = $this->get('https://localhost/login')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('nonce="'.Vite::cspNonce().'"', $response->getContent());
        $session = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
        $this->assertNotNull($session);
        $this->assertTrue($session->isSecure());
        $this->assertTrue($session->isHttpOnly());
        $this->assertSame('lax', $session->getSameSite());
    }

    public function test_enforcement_is_explicit_and_removes_report_only_policy(): void
    {
        config(['security.csp.report_only' => false]);
        $this->get('/__security/existing')->assertOk()
            ->assertHeaderMissing('Content-Security-Policy-Report-Only')
            ->assertHeaderMissing('Strict-Transport-Security');
        $this->assertStringContainsString("default-src 'self'", $this->get('/__security/existing')->headers->get('Content-Security-Policy'));
    }

    public function test_hsts_requires_production_https_and_explicit_opt_in(): void
    {
        $this->app->instance('env', 'production');
        $this->get('https://localhost/__security/html')->assertHeaderMissing('Strict-Transport-Security');
        config(['security.hsts.enabled' => true, 'security.hsts.max_age' => 300]);
        $this->get('http://localhost/__security/html')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/__security/html')->assertHeader('Strict-Transport-Security', 'max-age=300');
        $this->app->instance('env', 'local');
        $this->get('https://localhost/__security/html')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_untrusted_forwarded_https_does_not_enable_hsts(): void
    {
        $this->app->instance('env', 'production');
        config(['security.hsts.enabled' => true, 'security.trusted_proxies' => ['192.0.2.10']]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.20'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'spoofed.invalid'])
            ->get('http://localhost/__security/html')->assertHeaderMissing('Strict-Transport-Security');
        $this->assertSame('localhost', request()->getHost());
    }

    public function test_verified_proxy_can_forward_https(): void
    {
        $this->app->instance('env', 'production');
        config(['security.hsts.enabled' => true, 'security.trusted_proxies' => ['192.0.2.0/24']]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('http://localhost/__security/html')->assertHeader('Strict-Transport-Security', 'max-age=300');
    }

    public function test_wildcard_proxy_configuration_cannot_expand_trust(): void
    {
        $this->app->instance('env', 'production');
        config(['security.hsts.enabled' => true, 'security.trusted_proxies' => ['*', '**', 'REMOTE_ADDR', '192.0.2.10/99']]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('http://localhost/__security/html')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_policy_covers_upload_previews_and_same_origin_print_frames(): void
    {
        $policy = $this->get('/__security/html')->headers->get('Content-Security-Policy-Report-Only');
        foreach (["img-src 'self' data: blob:", "frame-src 'self'", "connect-src 'self'", "media-src 'self'"] as $directive) {
            $this->assertStringContainsString($directive, $policy);
        }
        $this->assertStringNotContainsString('api.telegram.org', $policy);
        $this->assertStringNotContainsString('api.line.me', $policy);
    }

    public function test_local_hmr_origins_are_derived_from_vite_and_never_allowed_in_production(): void
    {
        $hot = tempnam(sys_get_temp_dir(), 'security-vite-');
        file_put_contents($hot, 'http://localhost:5173');
        $previous = Vite::hotFile();
        Vite::useHotFile($hot);
        try {
            $this->app->instance('env', 'local');
            $policy = $this->get('/__security/html')->headers->get('Content-Security-Policy-Report-Only');
            $this->assertStringContainsString('http://localhost:5173', $policy);
            $this->assertStringContainsString('ws://localhost:5173', $policy);
            $this->app->instance('env', 'production');
            $this->assertStringNotContainsString('localhost:5173', $this->get('/__security/html')->headers->get('Content-Security-Policy-Report-Only'));
        } finally {
            Vite::useHotFile($previous);
            unlink($hot);
        }
    }

    public function test_security_headers_can_be_disabled_for_emergency_rollback(): void
    {
        config(['security.enabled' => false]);
        $this->get('/__security/html')->assertHeaderMissing('Content-Security-Policy-Report-Only')
            ->assertHeaderMissing('X-Frame-Options');
    }

    public function test_report_collector_is_stateless_and_logs_only_allowlisted_metadata(): void
    {
        Log::shouldReceive('notice')->once()->with('CSP violation', [
            'directive' => 'style-src-attr', 'disposition' => 'report',
        ]);
        $response = $this->call('POST', '/security/csp-reports', server: ['CONTENT_TYPE' => 'application/csp-report'], content: json_encode([
            'csp-report' => [
                'effective-directive' => 'style-src-attr',
                'document-uri' => 'https://localhost/private?token=must-not-log',
                'script-sample' => 'must-not-log',
            ],
        ]))->assertNoContent();
        $this->assertSame([], $response->headers->getCookies());
        $this->assertStringContainsString('report-uri /security/csp-reports', $response->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertNotContains(StartSession::class, app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName('security.csp-reports')));
    }

    public function test_report_collector_rejects_oversized_malformed_and_untrusted_data(): void
    {
        Log::shouldReceive('notice')->never();
        $this->call('POST', '/security/csp-reports', server: ['CONTENT_TYPE' => 'application/csp-report'], content: str_repeat('x', 16385))->assertStatus(413);
        $this->call('POST', '/security/csp-reports', server: ['CONTENT_TYPE' => 'text/plain'], content: '{}')->assertStatus(415);
        $this->call('POST', '/security/csp-reports', server: ['CONTENT_TYPE' => 'application/csp-report'], content: '{')->assertStatus(422);
        $this->postJson('/security/csp-reports', ['csp-report' => ['effective-directive' => "script-src\nforged-log"]])->assertStatus(422);
    }

    public function test_report_collector_is_rate_limited_and_does_not_disable_login_csrf(): void
    {
        Log::shouldReceive('notice')->times(30);
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/security/csp-reports', ['csp-report' => ['effective-directive' => 'script-src']])->assertNoContent();
        }
        $this->postJson('/security/csp-reports', ['csp-report' => ['effective-directive' => 'script-src']])->assertStatus(429);
        $this->app->instance('env', 'local'); // CSRF middleware intentionally bypasses only the testing environment.
        $this->postJson('/login', [])->assertStatus(419);
    }

    public function test_early_payload_errors_and_html_errors_keep_security_headers(): void
    {
        $this->withServerVariables(['CONTENT_LENGTH' => PHP_INT_MAX])->postJson('/login', [])
            ->assertStatus(413)->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->withServerVariables(['CONTENT_LENGTH' => 0]);
        config(['app.debug' => false]);
        foreach ([403, 404, 500] as $status) {
            $response = $this->get('/__security/error/'.$status)->assertStatus($status)
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
            $this->assertNotEmpty($response->headers->get('Content-Security-Policy-Report-Only'));
            $this->assertStringNotContainsString('<style', $response->getContent());
            $this->assertStringNotContainsString('style=', $response->getContent());
        }
    }

    public function test_stylesheet_and_safari_workaround_have_narrow_permissions(): void
    {
        $sources = config('security.csp.directives.style-src-attr');
        $this->assertSame([
            "'unsafe-hashes'",
            "'sha256-".base64_encode(hash('sha256', 'display:none !important', true))."'",
        ], $sources);
        $this->assertNotContains("'unsafe-inline'", $sources);
        $this->assertStringContainsString('sweetalert2@11.26.25/dist/sweetalert2.min.js', implode(' ', config('security.csp.directives.script-src')));
    }
}
