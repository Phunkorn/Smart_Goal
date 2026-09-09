<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkLog;
use App\Services\WorkLogParticipantService;
use App\Support\TodayWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ผู้ร่วมงานของบันทึกงานประจำวัน
 *
 * เคสที่ต้องรองรับคือ "ตรวจสอบคอมพิวเตอร์ตอนเช้าที่ขึ้นไปกันสองคน" ซึ่งต้อง
 * บันทึกครั้งเดียวแล้วติ๊กเพื่อนไปพร้อมกัน ไม่ใช่ให้ทั้งคู่พิมพ์บันทึกของตัวเอง
 *
 * เงื่อนไขว่าใครเพิ่มได้ ใช้กติกาเดียวกับผู้ร่วมงานของงานโครงการ คือแผนกเดียวกัน
 * บัญชีเปิดใช้งาน และ role เป็น user แต่ไม่มีขั้นตอนรออนุมัติ
 */
class WorkLogParticipantTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_add_a_colleague_from_the_same_department(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $colleague = $this->user($department);

        $this->actingAs($owner)->post(route('daily-logs.store'), [
            'title' => 'ตรวจสอบคอมพิวเตอร์ประจำวัน',
            'kind' => 'routine',
            'duration_minutes' => 40,
            'participants' => [$colleague->id],
        ])->assertRedirect();

        $log = WorkLog::query()->firstOrFail();

        $this->assertSame([$colleague->id], $log->participants->pluck('id')->all());
        $this->assertDatabaseHas('work_log_participants', [
            'work_log_id' => $log->id,
            'user_id' => $colleague->id,
            'added_by' => $owner->id,
        ]);
    }

    /**
     * ไม่มีขั้นตอนรออนุมัติ ต่างจากผู้ร่วมงานของงานโครงการโดยตั้งใจ
     * เพราะบันทึกงานประจำวันเป็นการบันทึกสิ่งที่เกิดขึ้นไปแล้ว
     */
    public function test_adding_a_participant_takes_effect_immediately(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $colleague = $this->user($department);
        $log = $this->log($owner, $department);

        app(WorkLogParticipantService::class)->sync($log, $owner, [$colleague->id]);

        $this->assertSame(1, $log->fresh()->participants()->count());
    }

    public function test_a_colleague_from_another_department_is_ignored(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $sales = Department::create(['department_name' => 'Sales']);
        $owner = $this->user($it);
        $outsider = $this->user($sales);

        $this->actingAs($owner)->post(route('daily-logs.store'), [
            'title' => 'งานทดสอบ',
            'kind' => 'routine',
            'participants' => [$outsider->id],
        ])->assertRedirect();

        $this->assertSame(0, WorkLog::query()->firstOrFail()->participants()->count());
    }

    public function test_inactive_accounts_and_non_user_roles_are_ignored(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $inactive = $this->user($department, 'user', false);
        $admin = $this->user($department, 'admin');
        $viewer = $this->user($department, 'viewer');
        $log = $this->log($owner, $department);

        app(WorkLogParticipantService::class)->sync($log, $owner, [$inactive->id, $admin->id, $viewer->id]);

        $this->assertSame(0, $log->fresh()->participants()->count());
    }

    /**
     * เจ้าของบันทึกไม่ใช่ "ผู้ร่วมงาน" ของตัวเอง เป็นคนละแนวคิดกัน
     */
    public function test_the_owner_cannot_be_their_own_participant(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $log = $this->log($owner, $department);

        app(WorkLogParticipantService::class)->sync($log, $owner, [$owner->id]);

        $this->assertSame(0, $log->fresh()->participants()->count());
    }

    public function test_the_same_person_cannot_be_added_twice(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $colleague = $this->user($department);
        $log = $this->log($owner, $department);

        app(WorkLogParticipantService::class)->sync($log, $owner, [$colleague->id, $colleague->id]);

        $this->assertSame(1, $log->fresh()->participants()->count());
    }

    public function test_updating_a_log_replaces_the_participant_list(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $first = $this->user($department);
        $second = $this->user($department);
        $log = $this->log($owner, $department);

        app(WorkLogParticipantService::class)->sync($log, $owner, [$first->id]);

        $this->actingAs($owner)->patch(route('daily-logs.update', $log), [
            'title' => $log->title,
            'kind' => 'routine',
            'participants' => [$second->id],
        ])->assertRedirect();

        $this->assertSame([$second->id], $log->fresh()->participants->pluck('id')->all());
    }

    public function test_participants_can_be_cleared_by_sending_nothing(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $colleague = $this->user($department);
        $log = $this->log($owner, $department);

        app(WorkLogParticipantService::class)->sync($log, $owner, [$colleague->id]);

        $this->actingAs($owner)->patch(route('daily-logs.update', $log), [
            'title' => $log->title,
            'kind' => 'routine',
        ])->assertRedirect();

        $this->assertSame(0, $log->fresh()->participants()->count());
    }

    /**
     * เคสหลักของฟีเจอร์: บันทึกงานพร้อมติ๊กเพื่อนได้ในครั้งเดียว
     * ไม่ต้องบันทึกก่อนแล้วเปิดกลับมาเพิ่มคนอีกรอบ
     */
    public function test_saving_a_log_can_add_participants_in_one_step(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $colleague = $this->user($department);

        $this->actingAs($owner)->post(route('daily-logs.store'), [
            'title' => 'ตรวจสอบคอมพิวเตอร์ประจำวัน',
            'kind' => 'routine',
            'participants' => [$colleague->id],
        ])->assertRedirect();

        $this->assertSame(1, WorkLog::query()->firstOrFail()->participants()->count());
    }

    public function test_participants_appear_in_the_timeline_and_the_ajax_payload(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $colleague = $this->user($department, 'user', true, 'สมชาย ใจดี');

        $response = $this->actingAs($owner)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->postJson(route('daily-logs.store'), [
                'title' => 'ตรวจสอบคอมพิวเตอร์ประจำวัน',
                'kind' => 'routine',
                'duration_minutes' => 40,
                'participants' => [$colleague->id],
            ])->assertOk();

        $this->assertSame($colleague->id, $response->json('log.participants.0.id'));
        $this->assertSame('สมชาย ใจดี', $response->json('log.participants.0.name'));
        $this->assertStringContainsString('สมชาย ใจดี', $response->json('html'));

        $this->actingAs($owner)
            ->get(route('daily-logs.index'))
            ->assertOk()
            ->assertSee('สมชาย ใจดี');
    }

    public function test_the_picker_offers_only_same_department_colleagues(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $sales = Department::create(['department_name' => 'Sales']);
        $owner = $this->user($it);
        $this->user($it, 'user', true, 'เพื่อนร่วมแผนก');
        $this->user($sales, 'user', true, 'คนต่างแผนก');

        $this->actingAs($owner)
            ->get(route('daily-logs.index'))
            ->assertOk()
            ->assertSee('เพื่อนร่วมแผนก')
            ->assertDontSee('คนต่างแผนก');
    }

    /**
     * หน้าที่อ่านอย่างเดียวของหัวหน้าต้องไม่มีตัวเลือกให้แก้ผู้ร่วมงานแทนลูกทีม
     */
    public function test_a_read_only_day_has_no_participant_picker(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $head = $this->user($department, 'user', true, 'หัวหน้า', true);
        $this->log($owner, $department);

        $this->actingAs($head)
            ->get(route('daily-logs.index', ['user' => $owner->id]))
            ->assertOk()
            ->assertDontSee('data-participant-picker', false);
    }

    public function test_participants_are_removed_when_the_log_is_force_deleted(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $colleague = $this->user($department);
        $log = $this->log($owner, $department);

        app(WorkLogParticipantService::class)->sync($log, $owner, [$colleague->id]);
        $this->assertSame(1, DB::table('work_log_participants')->count());

        $log->forceDelete();

        $this->assertSame(0, DB::table('work_log_participants')->count());
    }

    private function user(
        ?Department $department,
        string $role = 'user',
        bool $active = true,
        ?string $name = null,
        bool $head = false
    ): User {
        $attributes = [
            'role' => $role,
            'department_id' => $department?->id,
            'is_department_head' => $head,
            'is_active' => $active,
            'must_change_password' => false,
        ];

        if ($name !== null) {
            $attributes['name'] = $name;
        }

        return User::factory()->create($attributes);
    }

    private function log(User $owner, ?Department $department): WorkLog
    {
        return WorkLog::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'department_id' => $department?->id,
            'kind' => 'routine',
            'status' => 'done',
            'source' => 'manual',
            'title' => 'ตรวจสอบคอมพิวเตอร์ประจำวัน',
            'duration_minutes' => 40,
            'work_date' => TodayWorkspace::businessNow()->format('Y-m-d'),
        ]);
    }
}
