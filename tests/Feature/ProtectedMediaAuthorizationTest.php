<?php

namespace Tests\Feature;

use App\Models\JobImage;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Models\WorkOrderListAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProtectedMediaAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_task_attachment_access_follows_the_parent_task_policy(): void
    {
        $assignee = $this->user();
        $creator = $this->user();
        $leader = $this->user();
        $collaborator = $this->user();
        $pending = $this->user();
        $unrelated = $this->user();
        $task = $this->task($assignee, $creator, $leader);
        $task->collaborators()->attach($collaborator, ['status' => 'accepted']);
        $task->collaborators()->attach($pending, ['status' => 'pending']);
        $attachment = $this->taskAttachment($task);

        foreach ([$assignee, $creator, $leader, $collaborator, $this->user('admin'), $this->user('viewer')] as $actor) {
            $response = $this->actingAs($actor)
                ->get(route('media.task-attachments.show', $attachment))
                ->assertOk();
            $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }

        foreach ([$pending, $unrelated] as $actor) {
            $this->actingAs($actor)
                ->get(route('media.task-attachments.show', $attachment))
                ->assertForbidden();
        }

        $task->collaborators()->detach($collaborator);

        $this->actingAs($collaborator)
            ->get(route('media.task-attachments.show', $attachment))
            ->assertForbidden();
    }

    public function test_project_attachment_access_follows_project_membership_and_read_only_roles(): void
    {
        $owner = $this->user();
        $assignee = $this->user();
        $collaborator = $this->user();
        $pending = $this->user();
        $unrelated = $this->user();
        $project = WorkOrderList::create([
            'user_id' => $owner->id,
            'name' => 'Protected project',
            'is_visible' => true,
            'sort_order' => 1,
        ]);
        $task = $this->task($assignee, $owner, $owner, $project);
        $task->collaborators()->attach($collaborator, ['status' => 'accepted']);
        $task->collaborators()->attach($pending, ['status' => 'pending']);
        $attachment = $this->projectAttachment($project);

        foreach ([$owner, $assignee, $collaborator, $this->user('admin'), $this->user('viewer')] as $actor) {
            $this->actingAs($actor)
                ->get(route('media.project-attachments.show', $attachment))
                ->assertOk();
        }

        foreach ([$pending, $unrelated] as $actor) {
            $this->actingAs($actor)
                ->get(route('media.project-attachments.show', $attachment))
                ->assertForbidden();
        }

        $task->collaborators()->detach($collaborator);

        $this->actingAs($collaborator)
            ->get(route('media.project-attachments.show', $attachment))
            ->assertForbidden();
    }

    public function test_legacy_attachment_url_remains_compatible_but_is_authorized_and_migrated_private(): void
    {
        $owner = $this->user();
        $unrelated = $this->user();
        $task = $this->task($owner, $owner, $owner);
        $path = 'job-attachments/'.$task->job_id.'/legacy.txt';
        Storage::disk('public')->put($path, 'legacy attachment');
        $attachment = JobImage::create([
            'job_id' => $task->job_id,
            'file_path' => $path,
            'original_name' => 'legacy.txt',
            'file_type' => 'text/plain',
            'uploaded_by' => $owner->id,
        ]);

        $this->actingAs($owner)->get(route('media.show', ['path' => $path]))->assertOk();
        $this->actingAs($unrelated)->get(route('media.show', ['path' => $path]))->assertForbidden();

        $migration = require database_path('migrations/2026_08_24_000002_move_attachments_to_private_storage.php');
        $migration->up();
        $migration->up();

        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        $this->actingAs($owner)->get(route('media.task-attachments.show', $attachment))->assertOk();
        $this->actingAs($owner)->get(route('media.show', ['path' => $path]))->assertOk();
        $this->actingAs($owner)->get('/storage/'.$path)->assertForbidden();
    }

    public function test_migration_removes_an_identical_public_duplicate(): void
    {
        [$path] = $this->legacyTaskAttachment('identical attachment');
        Storage::disk('local')->put($path, 'identical attachment');

        $this->attachmentMigration()->up();

        Storage::disk('public')->assertMissing($path);
        $this->assertSame('identical attachment', Storage::disk('local')->get($path));
    }

    public function test_migration_preserves_public_source_when_private_destination_is_corrupted(): void
    {
        [$path] = $this->legacyTaskAttachment('complete public attachment');
        Storage::disk('local')->put($path, 'partial');

        try {
            $this->attachmentMigration()->up();
            $this->fail('The migration should reject a different private destination.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('verification failed', $exception->getMessage());
        }

        $this->assertSame('complete public attachment', Storage::disk('public')->get($path));
        $this->assertSame('partial', Storage::disk('local')->get($path));
    }

    public function test_migration_replaces_an_interrupted_temporary_copy_and_can_retry(): void
    {
        [$path] = $this->legacyTaskAttachment('complete source after interrupted copy');
        $temporaryPath = '.attachment-migration/'.hash('sha256', $path).'.part';
        Storage::disk('local')->put($temporaryPath, 'partial temporary copy');

        $migration = $this->attachmentMigration();
        $migration->up();
        $migration->up();

        Storage::disk('local')->assertMissing($temporaryPath);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame('complete source after interrupted copy', Storage::disk('local')->get($path));
    }

    public function test_migration_rejects_a_path_for_a_different_parent_and_preserves_source(): void
    {
        $owner = $this->user();
        $task = $this->task($owner, $owner, $owner);
        $path = 'job-attachments/'.($task->job_id + 999).'/wrong-parent.txt';
        Storage::disk('public')->put($path, 'must remain public on failure');
        JobImage::create([
            'job_id' => $task->job_id,
            'file_path' => $path,
            'original_name' => 'wrong-parent.txt',
            'file_type' => 'text/plain',
            'uploaded_by' => $owner->id,
        ]);

        try {
            $this->attachmentMigration()->up();
            $this->fail('The migration should reject an attachment under another parent path.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('unsafe or unexpected path', $exception->getMessage());
        }

        Storage::disk('public')->assertExists($path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_migration_uses_the_project_parent_id_for_project_attachments(): void
    {
        $owner = $this->user();
        $project = WorkOrderList::create([
            'user_id' => $owner->id,
            'name' => 'Legacy protected project',
            'is_visible' => true,
            'sort_order' => 1,
        ]);
        $path = 'project-attachments/'.$project->id.'/legacy-project.txt';
        Storage::disk('public')->put($path, 'legacy project attachment');
        WorkOrderListAttachment::create([
            'work_order_list_id' => $project->id,
            'file_path' => $path,
            'original_name' => 'legacy-project.txt',
            'file_type' => 'text/plain',
            'uploaded_by' => $owner->id,
        ]);

        $this->attachmentMigration()->up();

        Storage::disk('public')->assertMissing($path);
        $this->assertSame('legacy project attachment', Storage::disk('local')->get($path));
    }

    public function test_profile_images_have_a_separate_authenticated_contract(): void
    {
        $owner = $this->user();
        $viewer = $this->user();
        $path = 'profiles/profile.jpg';
        Storage::disk('public')->put($path, 'profile');
        $owner->update(['profile_image' => $path]);

        $this->actingAs($viewer)->get(route('media.profile', $owner))->assertOk();
        $this->actingAs($viewer)->get(route('media.show', ['path' => $path]))->assertOk();
        Storage::disk('public')->assertExists($path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_invalid_traversal_mismatched_and_missing_attachment_paths_return_not_found(): void
    {
        $owner = $this->user();
        $task = $this->task($owner, $owner, $owner);
        $mismatchedPath = 'job-attachments/'.($task->job_id + 999).'/wrong.txt';
        Storage::disk('local')->put($mismatchedPath, 'wrong owner path');
        $mismatched = JobImage::create([
            'job_id' => $task->job_id,
            'file_path' => $mismatchedPath,
            'original_name' => 'wrong.txt',
            'file_type' => 'text/plain',
            'uploaded_by' => $owner->id,
        ]);
        $missing = JobImage::create([
            'job_id' => $task->job_id,
            'file_path' => 'job-attachments/'.$task->job_id.'/missing.txt',
            'original_name' => 'missing.txt',
            'file_type' => 'text/plain',
            'uploaded_by' => $owner->id,
        ]);
        Storage::disk('public')->put('untracked/secret.txt', 'not a media record');

        $this->actingAs($owner)
            ->get(route('media.task-attachments.show', $mismatched))
            ->assertNotFound();
        $this->actingAs($owner)
            ->get(route('media.task-attachments.show', $missing))
            ->assertNotFound();
        $this->actingAs($owner)
            ->get(route('media.show', ['path' => 'untracked/secret.txt']))
            ->assertNotFound();
        $this->actingAs($owner)->get('/media/%2e%2e%2f.env')->assertNotFound();
        $this->actingAs($owner)->get('/media/job-attachments%2f..%2fprofiles%2fprofile.jpg')->assertNotFound();
    }

    public function test_private_storage_migration_fails_closed_for_an_unexpected_attachment_path(): void
    {
        $owner = $this->user();
        $task = $this->task($owner, $owner, $owner);
        JobImage::create([
            'job_id' => $task->job_id,
            'file_path' => 'profiles/not-a-task-attachment.jpg',
            'original_name' => 'not-a-task-attachment.jpg',
            'file_type' => 'image/jpeg',
            'uploaded_by' => $owner->id,
        ]);
        $migration = $this->attachmentMigration();

        try {
            $migration->up();
            $this->fail('The migration should reject a path outside the attachment contract.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('unsafe or unexpected path', $exception->getMessage());
        }
    }

    private function user(string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    /** @return array{string, JobImage} */
    private function legacyTaskAttachment(string $contents): array
    {
        $owner = $this->user();
        $task = $this->task($owner, $owner, $owner);
        $path = 'job-attachments/'.$task->job_id.'/legacy.txt';
        Storage::disk('public')->put($path, $contents);
        $attachment = JobImage::create([
            'job_id' => $task->job_id,
            'file_path' => $path,
            'original_name' => 'legacy.txt',
            'file_type' => 'text/plain',
            'uploaded_by' => $owner->id,
        ]);

        return [$path, $attachment];
    }

    private function attachmentMigration(): object
    {
        return require database_path('migrations/2026_08_24_000002_move_attachments_to_private_storage.php');
    }

    private function task(
        User $assignee,
        User $creator,
        User $leader,
        ?WorkOrderList $project = null
    ): WorkOrder {
        return WorkOrder::create([
            'user_id' => $assignee->id,
            'created_by' => $creator->id,
            'leader_user_id' => $leader->id,
            'work_order_list_id' => $project?->id,
            'job_topic' => 'Protected task',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_progress' => 0,
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
    }

    private function taskAttachment(WorkOrder $task): JobImage
    {
        $path = 'job-attachments/'.$task->job_id.'/evidence.txt';
        Storage::disk('local')->put($path, 'task attachment');

        return JobImage::create([
            'job_id' => $task->job_id,
            'file_path' => $path,
            'original_name' => 'evidence.txt',
            'file_type' => 'text/plain',
            'uploaded_by' => $task->created_by,
        ]);
    }

    private function projectAttachment(WorkOrderList $project): WorkOrderListAttachment
    {
        $path = 'project-attachments/'.$project->id.'/brief.txt';
        Storage::disk('local')->put($path, 'project attachment');

        return WorkOrderListAttachment::create([
            'work_order_list_id' => $project->id,
            'file_path' => $path,
            'original_name' => 'brief.txt',
            'file_type' => 'text/plain',
            'uploaded_by' => $project->user_id,
        ]);
    }
}
