<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkspaceBoard;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CspRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_documents_are_ready_for_csp_enforcement(): void
    {
        config(['security.csp.report_only' => false, 'app.debug' => false]);
        $this->assertCompatibleDocument(route('login'));

        $department = Department::create(['department_name' => 'CSP test department']);
        $user = User::factory()->create(['department_id' => $department->id, 'role' => 'user', 'must_change_password' => false]);
        $this->actingAs($user);
        foreach (['mytasks.index', 'daily-logs.index', 'social.index', 'notifications.index', 'workspace.index'] as $route) {
            $this->assertCompatibleDocument(route($route));
        }
        $this->assertCompatibleDocument(route('daily-logs.index', ['view' => 'monthly']));
        $user->update(['must_change_password' => true]);
        $this->assertCompatibleDocument(route('password.setup'));
        $user->update(['must_change_password' => false]);

        $board = WorkspaceBoard::create([
            'department_id' => $department->id, 'created_by' => $user->id,
            'title' => 'CSP board', 'visibility' => 'department',
        ]);
        $this->assertCompatibleDocument(route('workspace.boards.show', $board));

        $admin = User::factory()->create(['department_id' => $department->id, 'role' => 'admin', 'must_change_password' => false]);
        $this->actingAs($admin);
        foreach (['board.index', 'admin.departments.index', 'tickets.index', 'reports.projects'] as $route) {
            $this->assertCompatibleDocument(route($route));
        }
    }

    private function assertCompatibleDocument(string $url): void
    {
        $response = $this->get($url)->assertOk()->assertHeaderMissing('Content-Security-Policy-Report-Only');
        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertNotEmpty($policy, $url);
        preg_match("/'nonce-([^']+)'/", $policy, $nonce);
        $this->assertNotEmpty($nonce[1] ?? null, $url);

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        $xpath = new DOMXPath($dom);
        $this->assertSame(0, $xpath->query('//style | //*[@style]')->length, $url.' contains inline CSS');
        $this->assertSame(0, $xpath->query('//@*[starts-with(name(), "on")]')->length, $url.' contains an event attribute');
        foreach ($xpath->query('//script[not(@src) and not(@type="application/json")]') as $script) {
            $this->assertSame($nonce[1], $script->getAttribute('nonce'), $url.' has an unapproved inline script');
        }
    }
}
