<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkspaceBoard;
use App\Models\WorkspaceBoardAttachment;
use App\Models\WorkspaceBoardDocument;
use App\Services\WorkspaceAttachmentLifecycle;
use App\Services\WorkspaceBoardDocumentService;
use App\Services\WorkspaceBoardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceCollaborationTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private WorkspaceBoard $board;

    protected function setUp(): void
    {
        parent::setUp();
        $department = Department::create(['department_name' => 'Collaboration test']);
        $this->staff = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        $this->board = app(WorkspaceBoardService::class)->create($this->staff, $department, 'Test collaboration', 'organization');
        $this->actingAs($this->staff);
    }

    private function element(string $id = 'a', string $text = 'hello'): array
    {
        return ['id' => $id, 'type' => 'text', 'z' => 1, 'x' => 10, 'y' => 10, 'w' => 100, 'h' => 100, 'text' => $text, 'color' => '#1f2937', 'fontSize' => 20];
    }

    private function batch(int $version, array $operations, ?string $id = null): array
    {
        return ['base_version' => $version, 'request_id' => $id ?? (string) Str::uuid(), 'operations' => $operations];
    }

    public function test_object_batch_retry_is_idempotent_and_id_cannot_be_reused_for_other_content(): void
    {
        $url = route('workspace.boards.document.save', $this->board);
        $batch = $this->batch(1, [['type' => 'put', 'id' => 'a', 'element' => $this->element()]]);
        $this->putJson($url, $batch)->assertOk()->assertJsonPath('version', 2);
        $this->putJson($url, $batch)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('version', 2);
        $this->assertDatabaseCount('workspace_board_changes', 1);
        $batch['operations'][0]['element']['text'] = 'different';
        $this->putJson($url, $batch)->assertUnprocessable();
        $this->assertSame('hello', WorkspaceBoardDocument::find($this->board->id)->document['elements'][0]['text']);
    }

    public function test_revision_poll_is_small_read_only_and_changed_poll_returns_object_delta(): void
    {
        $url = route('workspace.boards.document', $this->board);
        DB::enableQueryLog();
        $response = $this->getJson($url.'?since=1')->assertOk()->assertJson(['version' => 1, 'unchanged' => true]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThan(100, strlen($response->getContent()));
        $this->assertSame([], array_values(array_filter($queries, fn ($q) => preg_match('/^(insert|update|delete)/i', $q['query']))));
        $this->assertArrayNotHasKey('document', $response->json());
        $this->putJson(route('workspace.boards.document.save', $this->board), $this->batch(1, [['type' => 'put', 'id' => 'a', 'element' => $this->element()]]))->assertOk();
        $this->getJson($url.'?since=1')->assertOk()->assertJsonPath('delta.upserts.0.id', 'a')->assertJsonMissingPath('document');
    }

    public function test_stale_batch_cannot_delete_other_work_and_can_be_rebased_without_full_snapshot_save(): void
    {
        $url = route('workspace.boards.document.save', $this->board);
        $this->putJson($url, $this->batch(1, [['type' => 'put', 'id' => 'a', 'element' => $this->element()]]))->assertOk();
        $op = [['type' => 'put', 'id' => 'b', 'element' => $this->element('b')]];
        $this->putJson($url, $this->batch(1, $op))->assertConflict()->assertJsonPath('document.elements.0.id', 'a');
        $this->putJson($url, $this->batch(2, $op))->assertOk()->assertJsonCount(2, 'document.elements');
    }

    public function test_missing_or_invalid_operation_ids_types_and_foreign_images_are_rejected(): void
    {
        $url = route('workspace.boards.document.save', $this->board);
        foreach ([['type' => 'execute', 'id' => 'a'], ['type' => 'delete', 'id' => '../x'], ['type' => 'put', 'id' => 'a', 'element' => $this->element('b')], ['type' => 'put', 'id' => 'a', 'element' => [...$this->element(), 'type' => 'image', 'attachmentId' => 999]]] as $op) {
            $this->putJson($url, $this->batch(1, [$op]))->assertUnprocessable();
        }
        $this->assertDatabaseCount('workspace_board_changes', 0);
    }

    public function test_viewer_polls_but_cannot_send_operations_and_private_board_is_department_isolated(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)->getJson(route('workspace.boards.document', $this->board).'?since=1')->assertOk();
        $this->putJson(route('workspace.boards.document.save', $this->board), $this->batch(1, [['type' => 'delete', 'id' => 'a']]))->assertForbidden();
        $this->board->update(['visibility' => 'department']);
        $this->getJson(route('workspace.boards.document', $this->board).'?since=1')->assertForbidden();
    }

    public function test_history_is_bounded_and_old_cursor_falls_back_to_snapshot(): void
    {
        $service = app(WorkspaceBoardDocumentService::class);
        for ($v = 1; $v <= 55; $v++) {
            $service->save($this->board, $this->staff, ['schema' => 1, 'elements' => [$this->element('a', "edit $v")]], $v);
        }
        $this->assertDatabaseCount('workspace_board_changes', WorkspaceBoardDocumentService::HISTORY_LIMIT);
        $this->getJson(route('workspace.boards.document', $this->board).'?since=1')->assertOk()->assertJsonPath('document.elements.0.text', 'edit 55')->assertJsonPath('version', 56);
    }

    public function test_unused_images_release_quota_but_keep_file_references_available_for_undo(): void
    {
        $image = WorkspaceBoardAttachment::create(['workspace_board_id' => $this->board->id, 'file_path' => 'test.png', 'original_name' => 'test.png', 'file_type' => 'image/png']);
        $document = ['schema' => 1, 'elements' => [[...$this->element(), 'type' => 'image', 'attachmentId' => $image->id]]];
        $service = app(WorkspaceBoardDocumentService::class);
        $service->save($this->board, $this->staff, $document, 1, [$image->id]);
        $this->assertSame(1, app(WorkspaceAttachmentLifecycle::class)->quotaCount($this->board));
        $service->save($this->board, $this->staff, ['schema' => 1, 'elements' => []], 2, [$image->id]);
        $this->assertSame(0, app(WorkspaceAttachmentLifecycle::class)->quotaCount($this->board));
        $this->assertNotNull($image->fresh()->released_at);
        $service->save($this->board, $this->staff, $document, 3, [$image->id]);
        $this->assertNull($image->fresh()->released_at);
        $this->deleteJson(route('workspace.boards.attachments.destroy', [$this->board, $image]))->assertConflict();
    }

    public function test_cleanup_skips_shared_references_and_preserves_recent_undo_images(): void
    {
        $image = WorkspaceBoardAttachment::create(['workspace_board_id' => $this->board->id, 'file_path' => 'test.png', 'original_name' => 'test.png', 'file_type' => 'image/png']);
        $lifecycle = app(WorkspaceAttachmentLifecycle::class);
        $this->assertSame(0, $lifecycle->prune($this->board));
        $this->travel(8)->days();
        $this->assertSame(1, $lifecycle->prune($this->board, true));
        $this->assertFalse($image->fresh()->trashed());
        $this->assertSame(1, $lifecycle->prune($this->board));
        $this->assertTrue(WorkspaceBoardAttachment::withTrashed()->find($image->id)->trashed());
    }

    public function test_two_image_objects_share_one_quota_slot_until_the_last_reference_is_removed(): void
    {
        $image = WorkspaceBoardAttachment::create(['workspace_board_id' => $this->board->id, 'file_path' => 'shared.png', 'original_name' => 'shared.png', 'file_type' => 'image/png']);
        $first = [...$this->element('image-a'), 'type' => 'image', 'attachmentId' => $image->id];
        $second = [...$first, 'id' => 'image-b', 'z' => 2];
        $service = app(WorkspaceBoardDocumentService::class);
        $copies = array_map(fn ($i) => [...$first, 'id' => 'copy-'.$i, 'z' => $i + 1], range(0, 30));
        $service->save($this->board, $this->staff, ['schema' => 1, 'elements' => $copies], 1, [$image->id]);
        $this->assertSame(1, app(WorkspaceAttachmentLifecycle::class)->quotaCount($this->board));
        $service->save($this->board, $this->staff, ['schema' => 1, 'elements' => [$second]], 2, [$image->id]);
        $this->assertSame(1, app(WorkspaceAttachmentLifecycle::class)->quotaCount($this->board));
        $this->travel(8)->days();
        $this->assertSame(0, app(WorkspaceAttachmentLifecycle::class)->prune($this->board));
        $this->assertNotNull($this->board->attachments()->find($image->id));
    }

    public function test_additive_migration_round_trip_preserves_legacy_snapshots_and_refuses_to_drop_active_history(): void
    {
        $original = WorkspaceBoardDocument::find($this->board->id)->document;
        $migration = require database_path('migrations/2026_11_25_000001_add_workspace_collaboration_history.php');
        $migration->down();
        $migration->up();
        $this->assertSame($original, WorkspaceBoardDocument::find($this->board->id)->document);
        app(WorkspaceBoardDocumentService::class)->save($this->board, $this->staff, ['schema' => 1, 'elements' => [$this->element()]], 1);
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_revision_polling_does_not_consume_the_upload_rate_limit(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->getJson(route('workspace.boards.document', $this->board).'?since=1')->assertOk();
        }
        // Validation, rather than 429, proves the independent upload bucket still accepts requests.
        $this->postJson(route('workspace.boards.attachments.store', $this->board), [])->assertUnprocessable();
    }
}
