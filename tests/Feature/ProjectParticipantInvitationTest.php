<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderShare;
use App\Models\WorkOrderShareRequest;
use App\Services\NotificationService;
use App\Support\ProjectRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * กติกาสมาชิกโปรเจกต์และการเข้าร่วมงานข้ามแผนก
 *
 * สเปก:
 *   - ผู้สร้างโปรเจกต์ = หัวหน้าโปรเจกต์ และไม่เปลี่ยนเพราะมีคนถูกเพิ่มเข้ามา
 *   - ทุกคนที่ถูกเพิ่มเป็น "ผู้เข้าร่วม" ไม่ว่าจะแผนกเดียวกันหรือต่างแผนก
 *   - ต่างแผนกเข้าร่วมได้ทันที ไม่มี pending ไม่มีคำขออนุมัติ ไม่มีหัวหน้าแผนกตัดสิน
 *   - หัวหน้าแผนกต้นสังกัดได้รับแจ้งเพื่อ "รับทราบและติดตาม" เท่านั้น
 *
 * ใช้สถานการณ์เดียวกับสเปก: User B · Marketing สร้างโปรเจกต์แล้วเพิ่ม
 * User A · IT, User C · HR และ User D · Marketing
 */
class ProjectParticipantInvitationTest extends TestCase
{
    use RefreshDatabase;

    private Department $marketing;

    private Department $it;

    private Department $hr;

    private User $leader;      // User B · Marketing — ผู้สร้างโปรเจกต์

    private User $itStaff;     // User A · IT

    private User $hrStaff;     // User C · HR

    private User $teammate;    // User D · Marketing

    private User $itHead;      // หัวหน้าแผนก IT

    protected function setUp(): void
    {
        parent::setUp();

        $this->marketing = Department::create(['department_name' => 'Marketing']);
        $this->it = Department::create(['department_name' => 'IT']);
        $this->hr = Department::create(['department_name' => 'HR']);

        $this->leader = $this->member($this->marketing, 'User B');
        $this->itStaff = $this->member($this->it, 'User A');
        $this->hrStaff = $this->member($this->hr, 'User C');
        $this->teammate = $this->member($this->marketing, 'User D');
        $this->itHead = $this->member($this->it, 'หัวหน้า IT', head: true);
    }

    /** 1 — ผู้สร้างโปรเจกต์เป็นหัวหน้าโปรเจกต์ทันทีที่สร้าง */
    public function test_the_creator_of_a_project_is_its_leader(): void
    {
        $response = $this->actingAs($this->leader)
            ->postJson(route('mytasks.create'), [
                'project_name' => 'Campaign 2027',
                'job_topic' => 'วางแผนแคมเปญ',
                'job_priority' => 2,
                'job_start_at' => now()->toDateString(),
                'job_due_at' => now()->addDays(5)->toDateString(),
            ])
            ->assertCreated();

        $project = WorkOrder::findOrFail($response->json('job_id'));

        $this->assertSame((int) $this->leader->id, (int) $project->created_by);
        $this->assertSame((int) $this->leader->id, ProjectRoles::leaderId($project));
        $this->assertTrue(ProjectRoles::isLeader($project, $this->leader));
    }

    /** 2 — เพิ่มคนในแผนกเดียวกัน เข้าร่วมได้ทันที */
    public function test_a_same_department_person_joins_immediately_as_a_participant(): void
    {
        $project = $this->project();

        $this->addParticipants($project, [$this->teammate]);

        $this->assertParticipating($project, $this->teammate);
        $this->assertFalse(ProjectRoles::isLeader($project->fresh(), $this->teammate));
    }

    /** 3, 4, 5 — เพิ่มคนต่างแผนก เข้าร่วมได้ทันที ไม่มี pending ไม่มีการรอหัวหน้าแผนก */
    public function test_a_cross_department_person_joins_immediately_without_any_approval(): void
    {
        $project = $this->project();

        $this->addParticipants($project, [$this->itStaff, $this->hrStaff]);

        foreach ([$this->itStaff, $this->hrStaff] as $participant) {
            $this->assertParticipating($project, $participant);
            $this->assertDatabaseMissing('work_order_collaborators', [
                'work_order_id' => $project->job_id,
                'user_id' => $participant->id,
                'status' => 'pending',
            ]);
        }

        // ห้ามมีคำขออนุมัติชนิดใดเกิดขึ้นจากเส้นทางนี้
        $this->assertDatabaseMissing('system_notifications', ['type' => 'collaborator_approval_request']);
        $this->assertDatabaseMissing('system_notifications', ['type' => 'cross_department_pending']);
        $this->assertSame(0, SystemNotification::whereIn('type', [
            'collaborator_approved',
            'collaborator_rejected',
        ])->count());

        // ผู้เข้าร่วมข้ามแผนกเปิดโปรเจกต์ได้ทันที
        $this->assertTrue($this->itStaff->fresh()->can('view', $project->fresh()));
    }

    /**
     * 6 — ผู้เข้าร่วมได้รับแจ้งเตือนว่าถูกเพิ่มเข้าร่วมโปรเจกต์
     *
     * ผู้เข้าร่วมข้ามแผนกต้องรู้จากตัวแจ้งเตือนเลยว่าเป็นงานข้ามแผนกของแผนกใด
     * ไม่ต้องเปิดเข้าโปรเจกต์ก่อนจึงจะเข้าใจว่าตัวเองไปช่วยงานของใคร
     */
    public function test_a_cross_department_participant_is_told_which_department_owns_the_project(): void
    {
        $project = $this->project();

        $this->addParticipants($project, [$this->itStaff]);

        $notification = SystemNotification::where('user_id', $this->itStaff->id)
            ->where('work_order_id', $project->job_id)
            ->where('type', 'collaborator_added')
            ->sole();

        $this->assertSame('คุณถูกเพิ่มเข้าร่วมโปรเจกต์ข้ามแผนก', $notification->title);
        $this->assertStringContainsString($this->leader->name, $notification->message);
        $this->assertStringContainsString($project->job_topic, $notification->message);
        $this->assertStringContainsString('งานข้ามแผนก', $notification->message);
        $this->assertStringContainsString('แผนกMarketing', $notification->message);
        $this->assertSame((int) $this->leader->id, (int) $notification->actor_user_id);
    }

    /** คนในแผนกเดียวกันไม่ต้องถูกบอกเรื่องข้ามแผนก เพราะไม่ได้ข้ามไปไหน */
    public function test_a_same_department_participant_gets_no_cross_department_wording(): void
    {
        $project = $this->project();

        $this->addParticipants($project, [$this->teammate]);

        $notification = SystemNotification::where('user_id', $this->teammate->id)
            ->where('work_order_id', $project->job_id)
            ->where('type', 'collaborator_added')
            ->sole();

        $this->assertSame('คุณถูกเพิ่มเข้าร่วมโปรเจกต์', $notification->title);
        $this->assertStringNotContainsString('ข้ามแผนก', $notification->message);
        $this->assertStringContainsString($project->job_topic, $notification->message);
    }

    /** 7, 8 — หัวหน้าแผนกต้นสังกัดได้แจ้งเตือนแบบรับทราบ ไม่มีปุ่มอนุมัติหรือปฏิเสธ */
    public function test_the_origin_department_head_gets_an_informational_notice_with_no_decision_to_make(): void
    {
        $project = $this->project();

        $this->addParticipants($project, [$this->itStaff]);

        $notification = SystemNotification::where('user_id', $this->itHead->id)
            ->where('work_order_id', $project->job_id)
            ->where('type', 'cross_department_participation')
            ->sole();

        $this->assertSame('พนักงานเข้าร่วมโปรเจกต์ข้ามแผนก', $notification->title);
        $this->assertStringContainsString($this->itStaff->name, $notification->message);
        $this->assertStringContainsString('IT', $notification->message);
        $this->assertStringContainsString('ของแผนกMarketing', $notification->message);
        $this->assertSame((int) $this->itStaff->id, (int) data_get($notification->data, 'participant_user_id'));

        // ไม่มีคำขอให้ตัดสิน และปลายทางคือหน้าติดตาม ไม่ใช่คิวอนุมัติ
        $notifications = app(NotificationService::class);
        $this->assertFalse($notifications->targetUnavailable($notification, $this->itHead));
        $this->assertSame(
            route('admin.approvals.index', ['approval_queue' => 'outgoing']),
            $notifications->target($notification, $this->itHead)
        );

        $this->actingAs($this->itHead)
            ->get(route('notifications.open', $notification))
            ->assertRedirect(route('admin.approvals.index', ['approval_queue' => 'outgoing']));

        // เส้นทางอนุมัติผู้ร่วมงานถูกถอดออกจากระบบแล้ว
        $this->assertFalse(Route::has('admin.tasks.collaborators.approval'));
    }

    /** หัวหน้าทุกคนของแผนกได้รับแจ้ง เมื่อแผนกมีหัวหน้ามากกว่าหนึ่งคน */
    public function test_every_head_of_the_origin_department_is_notified(): void
    {
        $secondHead = $this->member($this->it, 'หัวหน้า IT คนที่สอง', head: true);
        $project = $this->project();

        $this->addParticipants($project, [$this->itStaff]);

        foreach ([$this->itHead, $secondHead] as $head) {
            $this->assertDatabaseHas('system_notifications', [
                'user_id' => $head->id,
                'work_order_id' => $project->job_id,
                'type' => 'cross_department_participation',
            ]);
        }
    }

    /** การเพิ่มคนในแผนกเดียวกันไม่ใช่งานข้ามแผนก จึงไม่แจ้งหัวหน้าแผนกด้วยข้อความนี้ */
    public function test_a_same_department_participant_raises_no_cross_department_notice(): void
    {
        $marketingHead = $this->member($this->marketing, 'หัวหน้า Marketing', head: true);
        $project = $this->project();

        $this->addParticipants($project, [$this->teammate]);

        $this->assertDatabaseMissing('system_notifications', [
            'user_id' => $marketingHead->id,
            'type' => 'cross_department_participation',
        ]);
    }

    /** 9 — หัวหน้าแผนก IT เห็นได้ว่าลูกทีมกำลังร่วมโปรเจกต์ของ Marketing */
    public function test_the_origin_head_can_track_their_member_inside_another_departments_project(): void
    {
        $project = $this->project();
        $this->addParticipants($project, [$this->itStaff]);

        $this->actingAs($this->itHead)
            ->get(route('admin.approvals.index', ['approval_queue' => 'outgoing']))
            ->assertOk()
            ->assertSee('งานข้ามแผนกของทีม')
            ->assertSee($this->itStaff->name)
            ->assertSee($project->job_topic)
            ->assertSee('หัวหน้าโปรเจกต์')
            ->assertSee($this->leader->name)
            ->assertSee('กำลังเข้าร่วม')
            // หน้าติดตามต้องไม่มีปุ่มตัดสินใจใด ๆ
            ->assertDontSee('data-approval-form', false);
    }

    /** 10, 11 — หัวหน้าโปรเจกต์ยังเป็นผู้สร้างเดิม และผู้เข้าร่วมไม่ได้เลื่อนเป็นหัวหน้า */
    public function test_adding_participants_never_moves_project_leadership(): void
    {
        $project = $this->project();
        $leaderBefore = [$project->created_by, $project->leader_user_id, $project->user_id];

        $this->addParticipants($project, [$this->itStaff, $this->hrStaff, $this->teammate]);

        $project->refresh();
        $this->assertSame($leaderBefore, [$project->created_by, $project->leader_user_id, $project->user_id],
            'การเพิ่มผู้เข้าร่วมต้องไม่แก้ไขฟิลด์ที่บอกว่าใครเป็นหัวหน้าโปรเจกต์');
        $this->assertSame((int) $this->leader->id, ProjectRoles::leaderId($project));

        $project->load(['creator', 'user', 'collaborators']);
        $participantIds = ProjectRoles::participants($project)->pluck('user.id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame(
            [(int) $this->itStaff->id, (int) $this->hrStaff->id, (int) $this->teammate->id],
            $participantIds,
            'ผู้สร้างที่มอบหมายงานให้ตัวเองคือหัวหน้าโปรเจกต์ ไม่ใช่ผู้เข้าร่วมของตัวเอง'
        );

        // ผู้เข้าร่วมไม่ได้สิทธิ์จัดการสมาชิกติดมาด้วย
        foreach ([$this->itStaff, $this->hrStaff, $this->teammate] as $participant) {
            $this->assertFalse($participant->fresh()->can('manageTeam', $project->fresh()));
        }
    }

    /** ป้ายข้ามแผนกเป็นข้อมูลแสดงผล ไม่ใช่สถานะของการอนุมัติ */
    public function test_cross_department_is_only_a_display_marker_on_the_participant_list(): void
    {
        $project = $this->project();
        $this->addParticipants($project, [$this->itStaff, $this->teammate]);

        $project = $project->fresh()->load(['creator', 'user', 'collaborators']);
        $participants = ProjectRoles::participants($project)->keyBy(fn (array $entry) => (int) $entry['user']->id);

        $this->assertTrue($participants[(int) $this->itStaff->id]['cross_department']);
        $this->assertFalse($participants[(int) $this->teammate->id]['cross_department']);
        $this->assertSame('accepted', $participants[(int) $this->itStaff->id]['status']);
        $this->assertSame('accepted', $participants[(int) $this->teammate->id]['status']);
    }

    /** 12 — แผนกที่ไม่มีหัวหน้ายังเพิ่มผู้เข้าร่วมได้ และไม่ตกไปหา admin */
    public function test_a_department_without_a_head_still_lets_its_member_join_and_never_falls_back_to_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'department_id' => null, 'is_active' => true]);
        $project = $this->project();

        // HR ไม่มีหัวหน้าแผนกเลย
        $this->addParticipants($project, [$this->hrStaff]);

        $this->assertParticipating($project, $this->hrStaff);
        $this->assertDatabaseMissing('system_notifications', ['user_id' => $admin->id]);
        $this->assertSame(0, SystemNotification::where('type', 'cross_department_participation')->count());
    }

    /** 14 — คนที่ไม่ใช่หัวหน้าโปรเจกต์แก้สมาชิกไม่ได้ */
    public function test_only_the_project_leader_side_can_change_the_member_list(): void
    {
        $project = $this->project();
        $this->addParticipants($project, [$this->itStaff]);

        // ผู้เข้าร่วมเพิ่มคนอื่นไม่ได้
        $this->actingAs($this->itStaff)
            ->postJson(route('tasks.collaborators.store', $project->job_id), ['collaborators' => [$this->hrStaff->id]])
            ->assertForbidden();

        // คนนอกโปรเจกต์ก็เช่นกัน
        $this->actingAs($this->hrStaff)
            ->postJson(route('tasks.collaborators.store', $project->job_id), ['collaborators' => [$this->teammate->id]])
            ->assertForbidden();

        // ผู้เข้าร่วมถอนตัวเองออกไม่ได้ ต้องให้หัวหน้าโปรเจกต์นำออก
        $this->actingAs($this->itStaff)
            ->deleteJson(route('tasks.collaborators.destroy', [$project->job_id, $this->itStaff->id]))
            ->assertForbidden();

        $this->actingAs($this->leader)
            ->deleteJson(route('tasks.collaborators.destroy', [$project->job_id, $this->itStaff->id]))
            ->assertSuccessful();

        $this->assertFalse($project->fresh()->collaborators->contains('id', $this->itStaff->id));

        // หัวหน้าโปรเจกต์เองถูกนำออกไม่ได้
        $this->actingAs($this->leader)
            ->deleteJson(route('tasks.collaborators.destroy', [$project->job_id, $this->leader->id]))
            ->assertStatus(422);
    }

    /**
     * หัวหน้าแผนกเพิ่มลูกทีมของตัวเองเข้าโปรเจกต์ที่ปลายทางเป็นแผนกอื่น
     *
     * เคสนี้เคยเป็นช่องที่คำขอวนกลับมาหาผู้เชิญเอง แล้วค้างเงียบโดยไม่มีใครได้รับแจ้ง
     * (เดิมผู้อนุมัติคือหัวหน้าแผนกของผู้ถูกเชิญ ซึ่งคือผู้เชิญคนเดิม)
     * กติกาใหม่ไม่มีคำขอเลย จึงไม่มีสถานะค้างให้เกิดขึ้นได้อีก
     */
    public function test_a_head_adding_their_own_member_into_another_departments_project_never_stalls(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'department_id' => null, 'is_active' => true]);

        // โปรเจกต์ปลายทางเป็น Marketing แต่หัวหน้า IT เป็นผู้สร้างจึงเป็นหัวหน้าโปรเจกต์
        $project = WorkOrder::create([
            'user_id' => $this->leader->id,
            'created_by' => $this->itHead->id,
            'assigned_by' => $this->itHead->id,
            'leader_user_id' => $this->itHead->id,
            'department_id' => $this->marketing->id,
            'job_topic' => 'ปิดงบแคมเปญ',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);

        $this->actingAs($this->itHead)
            ->postJson(route('tasks.collaborators.store', $project->job_id), ['collaborators' => [$this->itStaff->id]])
            ->assertSuccessful();

        $pivot = $project->fresh()->collaborators->firstWhere('id', $this->itStaff->id);
        $this->assertNotNull($pivot, 'ผู้ที่ถูกเพิ่มต้องอยู่ในโปรเจกต์');
        $this->assertSame('accepted', $pivot->pivot->status, 'ต้องไม่มีสถานะรออนุมัติที่ไม่มีใครไปกดได้');
        $this->assertTrue($this->itStaff->fresh()->can('view', $project->fresh()));
        $this->assertSame((int) $this->itHead->id, ProjectRoles::leaderId($project->fresh()));

        // ไม่มีคำขอค้าง และไม่มีอะไรตกไปหา admin
        $this->assertFalse(SystemNotification::where('type', 'collaborator_approval_request')->exists());
        $this->assertFalse(SystemNotification::where('user_id', $admin->id)->exists());
        $this->assertTrue(SystemNotification::where('user_id', $this->itStaff->id)
            ->where('type', 'collaborator_added')->exists());

        // ผู้เพิ่มคือหัวหน้าแผนกต้นสังกัดเอง จึงไม่ต้องแจ้งตัวเองให้รับทราบ
        $this->assertFalse(SystemNotification::where('user_id', $this->itHead->id)
            ->where('type', 'cross_department_participation')->exists());
    }

    /**
     * 13 — Approval Flow อื่นที่ไม่ใช่การเพิ่มผู้เข้าร่วมโดยตรง ต้องทำงานเหมือนเดิม
     *
     * สองเส้นทางนี้ใช้กลไกคนละชุดกับการเพิ่มสมาชิกโปรเจกต์ และยังต้องมีคนตัดสิน:
     *   - มอบหมายงานข้ามแผนก (work_orders.approval_status) — หัวหน้าแผนกปลายทางอนุมัติ
     *   - ผู้ใช้กดขอเข้าร่วมงานที่ถูกแชร์ — ผู้แชร์หรือหัวหน้าแผนกของผู้แชร์อนุมัติ
     */
    public function test_unrelated_approval_flows_still_require_a_decision(): void
    {
        // มอบหมายงานข้ามแผนกยังต้องรออนุมัติ
        $response = $this->actingAs($this->leader)
            ->postJson(route('mytasks.create'), [
                'project_name' => 'งานข้ามแผนก',
                'job_topic' => 'ตั้งเครื่องใหม่',
                'user_id' => $this->itStaff->id,
                'job_priority' => 2,
                'job_start_at' => now()->toDateString(),
                'job_due_at' => now()->addDay()->toDateString(),
            ])
            ->assertCreated();

        $assigned = WorkOrder::findOrFail($response->json('job_id'));
        $this->assertSame('pending', $assigned->approval_status);
        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $this->itHead->id,
            'work_order_id' => $assigned->job_id,
            'type' => 'cross_department_pending',
        ]);

        // คำขอเข้าร่วมจากการแชร์งานยังต้องผ่านผู้ตัดสินเหมือนเดิม
        $marketingHead = $this->member($this->marketing, 'หัวหน้า Marketing', head: true);
        $project = $this->project();
        $subtask = WorkOrder::create([
            'user_id' => $this->leader->id,
            'created_by' => $this->leader->id,
            'leader_user_id' => $this->leader->id,
            'parent_job_id' => $project->job_id,
            'department_id' => $this->marketing->id,
            'job_topic' => 'งานย่อยที่เปิดให้ขอเข้าร่วม',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);

        $this->actingAs($this->leader)
            ->postJson(route('shares.store', $subtask->job_id), ['scope' => 'organization'])
            ->assertCreated();

        $share = WorkOrderShare::where('work_order_id', $subtask->job_id)->firstOrFail();

        $this->actingAs($this->itStaff)
            ->postJson(route('shares.requests.store', $share))
            ->assertCreated();

        $shareRequest = WorkOrderShareRequest::where('work_order_share_id', $share->id)->firstOrFail();
        $this->assertSame('pending', $shareRequest->status, 'ผู้ใช้ที่กดขอเข้าร่วมเองยังต้องรอการตัดสิน');
        $this->assertFalse($subtask->fresh()->collaborators->contains('id', $this->itStaff->id));

        // ผู้แชร์ที่ไม่ใช่หัวหน้าแผนกชี้ขาดเองไม่ได้ คำขอยังต้องผ่านชั้นที่สองเหมือนเดิม
        $this->actingAs($this->leader)
            ->patchJson(route('shares.requests.approve', $shareRequest))
            ->assertOk();

        $this->assertSame('awaiting_head', $shareRequest->fresh()->status);
        $this->assertFalse($subtask->fresh()->collaborators->contains('id', $this->itStaff->id));
        $this->assertDatabaseHas('system_notifications', ['type' => 'share_join_awaiting_head']);

        $this->actingAs($marketingHead)
            ->patchJson(route('shares.requests.approve', $shareRequest))
            ->assertOk();

        $this->assertSame('approved', $shareRequest->fresh()->status);
        $this->assertSame('accepted',
            $subtask->fresh()->collaborators()->findOrFail($this->itStaff->id)->pivot->status);
    }

    private function member(Department $department, string $name, bool $head = false): User
    {
        return User::factory()->create([
            'name' => $name,
            'role' => 'user',
            'department_id' => $department->id,
            'is_department_head' => $head,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    /** โปรเจกต์ของ Marketing ที่ User B สร้างและรับผิดชอบเอง */
    private function project(): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $this->leader->id,
            'created_by' => $this->leader->id,
            'assigned_by' => $this->leader->id,
            'leader_user_id' => $this->leader->id,
            'department_id' => $this->marketing->id,
            'job_topic' => 'Campaign 2027',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now(),
            'job_due_at' => now()->addDays(5),
        ]);
    }

    /** @param  array<int, User>  $participants */
    private function addParticipants(WorkOrder $project, array $participants): void
    {
        $this->actingAs($this->leader)
            ->postJson(route('tasks.collaborators.store', $project->job_id), [
                'collaborators' => collect($participants)->pluck('id')->all(),
            ])
            ->assertOk();
    }

    private function assertParticipating(WorkOrder $project, User $participant): void
    {
        $pivot = $project->fresh()->collaborators()->findOrFail($participant->id)->pivot;

        $this->assertSame('accepted', $pivot->status, $participant->name.' ต้องเข้าร่วมโปรเจกต์ได้ทันที');
        $this->assertNotNull($pivot->responded_at);
        $this->assertSame((int) $this->leader->id, (int) $pivot->added_by);
        $this->assertSame((int) $this->leader->id, (int) $pivot->decided_by);
    }
}
