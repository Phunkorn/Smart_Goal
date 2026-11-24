<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_read_deployment_diagnostics(): void
    {
        $this->getJson(route('settings.index', ['security_headers' => 1]))
            ->assertUnauthorized()->assertJsonMissingPath('request.peer_address');
    }

    public function test_non_administrators_cannot_read_deployment_diagnostics(): void
    {
        foreach (['user', 'viewer'] as $role) {
            $user = User::factory()->create(['role' => $role, 'must_change_password' => false]);
            $this->actingAs($user)->getJson(route('settings.index', ['security_headers' => 1]))
                ->assertForbidden()->assertJsonMissingPath('request.peer_address');
        }
    }

    public function test_admin_reads_effective_policy_without_secrets_or_untrusted_https(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
        config(['security.trusted_proxies' => [], 'security.hsts.enabled' => true]);
        $this->app->instance('env', 'production');
        $response = $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => '192.0.2.5'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => 'sensitive-client-metadata'])
            ->getJson('http://localhost/settings?security_headers=1')->assertOk()
            ->assertJsonPath('request.is_secure', false)
            ->assertJsonPath('request.peer_address', '192.0.2.5')
            ->assertJsonPath('request.peer_is_trusted', false)
            ->assertJsonPath('headers.Strict-Transport-Security', null)
            ->assertJsonMissingPath('app_key')
            ->assertJsonMissingPath('database');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('sensitive-client-metadata', $response->getContent());
        $this->assertSame($response->headers->get('Content-Security-Policy-Report-Only'), $response->json('headers.Content-Security-Policy-Report-Only'));
    }

    public function test_admin_sees_hsts_only_for_effective_production_https(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
        config(['security.hsts.enabled' => true, 'security.hsts.max_age' => 300, 'security.csp.report_only' => false]);
        $this->app->instance('env', 'production');
        $this->actingAs($admin)->getJson('https://localhost/settings?security_headers=1')
            ->assertOk()->assertJsonPath('request.is_secure', true)
            ->assertJsonPath('headers.Strict-Transport-Security', 'max-age=300')
            ->assertJsonPath('headers.Content-Security-Policy-Report-Only', null)
            ->assertHeader('Strict-Transport-Security', 'max-age=300');
    }
}
